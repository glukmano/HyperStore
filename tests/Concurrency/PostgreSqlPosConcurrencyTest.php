<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\MarketCurrency;
use App\Core\Markets\Models\StoreMarket;
use App\Core\Stores\Models\Store;
use App\Core\Stores\Models\StoreUser;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Order\Models\Order;
use Modules\POS\Models\PosRegister;
use Modules\POS\Models\PosRegisterSession;
use Modules\POS\Services\PosRegisterSessionService;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Tests\TestCase;

/**
 * Owner Delta §1/§3 (register-session race) and the mandated "real
 * Postgres POS-vs-web same-final-unit race test" (§ inventory concurrency,
 * D.13) and §11 (duplicate sale-submission idempotency).
 */
class PostgreSqlPosConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $webChannel;

    private InventorySource $source;

    private StockItem $stockItem;

    private User $cashier;

    private PosRegister $register;

    private Product $product;

    private ShippingMethod $shippingMethod;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'hyperstore',
            'database.connections.pgsql.username' => 'lukman',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 5432,
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSqlPosConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $uid = uniqid();
        $this->tenant = Tenant::create(['name' => 'POS Conc Tenant', 'slug' => 'pos-conc-'.$uid, 'status' => 'active']);
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'PC_S_'.$uid, 'name' => 'Store', 'slug' => 'pc-s-'.$uid, 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'PC_M_'.$uid, 'name' => 'Market', 'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'is_active' => true]);
        MarketCurrency::create(['market_id' => $this->market->id, 'currency_code' => 'USD', 'is_default' => true]);
        $storeMarket = StoreMarket::create(['store_id' => $this->store->id, 'market_id' => $this->market->id, 'is_active' => true, 'is_default' => true]);

        $this->webChannel = Channel::firstOrCreate(['handle' => 'pc-web-'.$uid], ['type' => 'website', 'name' => 'Web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->webChannel->id]);

        $posChannel = Channel::create(['type' => 'pos', 'name' => 'POS', 'handle' => 'pc-pos-'.$uid, 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $posChannel->id]);

        $this->cashier = User::factory()->create();
        StoreUser::create(['store_id' => $this->store->id, 'user_id' => $this->cashier->id, 'role' => 'cashier', 'is_active' => true]);

        $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'PC_WH_'.$uid, 'name' => 'WH', 'country_code' => 'US', 'status' => 'active']);
        $this->source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'PC_SRC_'.$uid, 'name' => 'Source', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        $this->register = PosRegister::create([
            'tenant_id' => $this->tenant->id,
            'store_market_id' => $storeMarket->id,
            'channel_id' => $posChannel->id,
            'inventory_source_id' => $this->source->id,
            'code' => 'PCREG',
            'name' => 'Conc Register',
            'status' => 'active',
        ]);

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'PC_TAX_'.$uid, 'name' => 'Tax', 'is_default' => true]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'PC-SKU-'.$uid,
            'name' => 'Conc Product',
            'slug' => 'pc-product-'.$uid,
            'product_type' => 'physical',
            'status' => 'active',
        ]);
        ProductStoreListing::create(['product_id' => $this->product->id, 'store_id' => $this->store->id, 'status' => 'published']);
        $this->stockItem = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->source->id, 'product_id' => $this->product->id, 'on_hand' => 1, 'reserved' => 0]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'PC_PB_'.$uid, 'name' => 'PB', 'currency' => 'USD', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->product->id, 'amount_minor' => 1000, 'currency' => 'USD', 'status' => 'active']);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'PC_ZONE_'.$uid, 'name' => 'Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'US']);
        $this->shippingMethod = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PC_FLAT_'.$uid,
            'name' => 'Flat',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'USD',
            'base_amount' => 0,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->shippingMethod->id, 'shipping_zone_id' => $zone->id]);
    }

    /**
     * @param  list<string>  $scripts
     * @return list<array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/pos_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $synced = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_pos_{$idx}_".uniqid().'.php';
            file_put_contents($tmpFile, $synced);

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open('php '.escapeshellarg($tmpFile), $descriptors, $pipes[$idx]);
            $processes[$idx] = ['resource' => $proc, 'tmp_file' => $tmpFile];
        }

        usleep(50000);
        touch($barrierFile);

        $results = [];
        foreach ($processes as $idx => $procInfo) {
            $stdout = stream_get_contents($pipes[$idx][1]);
            $stderr = stream_get_contents($pipes[$idx][2]);
            fclose($pipes[$idx][0]);
            fclose($pipes[$idx][1]);
            fclose($pipes[$idx][2]);
            $exitCode = proc_close($procInfo['resource']);
            @unlink($procInfo['tmp_file']);
            $results[$idx] = ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        @unlink($barrierFile);

        return $results;
    }

    private function bootstrapScript(): string
    {
        $bp = base_path();

        return "<?php
require '{$bp}/vendor/autoload.php';
\$app = require_once '{$bp}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
Illuminate\\Support\\Facades\\DB::purge('pgsql');
Illuminate\\Support\\Facades\\DB::setDefaultConnection('pgsql');
";
    }

    public function test_two_workers_racing_to_open_the_same_register_result_in_exactly_one_active_session(): void
    {
        $bootstrap = $this->bootstrapScript();
        $registerId = $this->register->id;
        $cashierId = $this->cashier->id;

        $worker = "{$bootstrap}
use Modules\\POS\\Models\\PosRegister;
use Modules\\POS\\Services\\PosRegisterSessionService;

// __BARRIER_WAIT__
try {
    \$register = PosRegister::find({$registerId});
    app(PosRegisterSessionService::class)->open(\$register, {$cashierId}, 'USD', 0);
    echo 'OPENED';
} catch (\\Throwable \$e) {
    echo 'REJECTED:' . get_class(\$e);
}
";

        $results = $this->executeConcurrently([$worker, $worker]);
        $outputs = array_column($results, 'stdout');

        $openedCount = count(array_filter($outputs, fn ($o) => str_contains($o, 'OPENED')));
        $rejectedCount = count(array_filter($outputs, fn ($o) => str_contains($o, 'REJECTED')));

        $this->assertSame(1, $openedCount, 'Exactly one worker must succeed in opening the register.');
        $this->assertSame(1, $rejectedCount, 'The conflicting concurrent open attempt must be rejected.');
        $this->assertSame(1, PosRegisterSession::where('register_id', $registerId)->where('status', 'active')->count());
    }

    /**
     * The mandated "real Postgres POS-vs-web same-final-unit race test":
     * a POS sale and a web Checkout simultaneously attempt to reserve the
     * last unit of stock — exactly one succeeds.
     */
    public function test_pos_sale_and_web_checkout_cannot_both_sell_the_last_unit_of_stock(): void
    {
        $bootstrap = $this->bootstrapScript();
        $sessionId = app(PosRegisterSessionService::class)->open($this->register, $this->cashier->id, 'USD', 10000)->id;
        $tenantId = $this->tenant->id;
        $productId = $this->product->id;
        $storeId = $this->store->id;
        $marketId = $this->market->id;
        $webChannelId = $this->webChannel->id;

        $posWorker = "{$bootstrap}
use Modules\\Cart\\ValueObjects\\CartLineItemData;
use Modules\\Cart\\ValueObjects\\CartQuantity;
use Modules\\Cart\\Contracts\\CartServiceInterface;
use Modules\\POS\\Models\\PosRegisterSession;
use Modules\\POS\\Services\\PosSaleOrchestrator;
use Modules\\POS\\DTOs\\PosTenderSelection;

// __BARRIER_WAIT__
try {
    \$session = PosRegisterSession::find({$sessionId});
    \$orchestrator = app(PosSaleOrchestrator::class);
    \$cart = \$orchestrator->getOrCreateCart(\$session, null, 'race-guest-pos');
    app(CartServiceInterface::class)->addLine(\$cart, new CartLineItemData({$productId}, null, CartQuantity::fromInt(1)));
    \$orchestrator->completeSale(\$session, \$cart, 'race-pos-sale', new PosTenderSelection(cashAmountMinor: 1000));
    echo 'POS_SUCCESS';
} catch (\\Throwable \$e) {
    echo 'POS_FAIL:' . \$e->getMessage();
}
";

        $webWorker = "{$bootstrap}
use Modules\\Cart\\Models\\Cart;
use Modules\\Cart\\ValueObjects\\CartLineItemData;
use Modules\\Cart\\ValueObjects\\CartQuantity;
use Modules\\Cart\\Contracts\\CartServiceInterface;
use Modules\\Cart\\ValueObjects\\CartContext;
use Modules\\Checkout\\Contracts\\CheckoutOrchestratorInterface;
use Modules\\Checkout\\DTOs\\CheckoutAddress;
use Modules\\Checkout\\DTOs\\CheckoutCustomerData;

// __BARRIER_WAIT__
try {
    \$ctx = new CartContext(tenantId: {$tenantId}, storeId: {$storeId}, marketId: {$marketId}, channelId: {$webChannelId}, currency: 'USD', guestToken: 'race-guest-web');
    \$cart = app(CartServiceInterface::class)->getOrCreateActiveCart(\$ctx);
    app(CartServiceInterface::class)->addLine(\$cart, new CartLineItemData({$productId}, null, CartQuantity::fromInt(1)));

    \$co = app(CheckoutOrchestratorInterface::class);
    \$session = \$co->createFromCart(\$cart);
    \$co->setCustomerData(\$session, new CheckoutCustomerData('web@example.com', 'Web', 'User'));
    \$co->setAddresses(\$session, new CheckoutAddress('Web User', ['Street 1'], 'City', 'US', postalCode: '10001'));
    \$rates = \$co->getShippingRates(\$session);
    \$quote = \$rates['shipping_result']->quotes->first();
    \$co->selectShippingQuote(\$session, ['method_id' => \$quote->methodId, 'method_code' => \$quote->methodCode]);
    \$co->reserveInventory(\$session);
    echo 'WEB_SUCCESS';
} catch (\\Throwable \$e) {
    echo 'WEB_FAIL:' . \$e->getMessage();
}
";

        $results = $this->executeConcurrently([$posWorker, $webWorker]);
        $outputs = array_column($results, 'stdout');

        $successCount = count(array_filter($outputs, fn ($o) => str_contains($o, '_SUCCESS')));
        $failCount = count(array_filter($outputs, fn ($o) => str_contains($o, '_FAIL')));

        $this->assertSame(1, $successCount, 'Exactly one of the POS sale / web checkout must succeed in reserving the last unit.');
        $this->assertSame(1, $failCount, 'The other must fail on insufficient stock — never oversold.');
    }

    public function test_duplicate_concurrent_sale_submission_never_creates_a_second_order(): void
    {
        $bootstrap = $this->bootstrapScript();
        $this->stockItem->update(['on_hand' => 100]);
        $sessionId = app(PosRegisterSessionService::class)->open($this->register, $this->cashier->id, 'USD', 10000)->id;
        $productId = $this->product->id;

        $worker = "{$bootstrap}
use Modules\\Cart\\ValueObjects\\CartLineItemData;
use Modules\\Cart\\ValueObjects\\CartQuantity;
use Modules\\Cart\\Contracts\\CartServiceInterface;
use Modules\\POS\\Models\\PosRegisterSession;
use Modules\\POS\\Services\\PosSaleOrchestrator;
use Modules\\POS\\DTOs\\PosTenderSelection;

// __BARRIER_WAIT__
try {
    \$session = PosRegisterSession::find({$sessionId});
    \$orchestrator = app(PosSaleOrchestrator::class);
    \$cart = \$orchestrator->getOrCreateCart(\$session, null, 'dup-guest');
    app(CartServiceInterface::class)->addLine(\$cart, new CartLineItemData({$productId}, null, CartQuantity::fromInt(1)));
    \$result = \$orchestrator->completeSale(\$session, \$cart, 'dup-client-sale-id', new PosTenderSelection(cashAmountMinor: 1000));
    echo 'ORDER:' . \$result->order->id;
} catch (\\Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
";

        $results = $this->executeConcurrently([$worker, $worker]);
        $outputs = array_column($results, 'stdout');

        $orderIds = [];
        foreach ($outputs as $out) {
            if (preg_match('/ORDER:(\d+)/', $out, $m)) {
                $orderIds[] = (int) $m[1];
            }
        }

        $this->assertCount(2, $orderIds, 'Both concurrent submissions must resolve successfully (idempotent replay).');
        $this->assertSame($orderIds[0], $orderIds[1], 'Both concurrent submissions of the same client_sale_id must resolve to the SAME Order.');
        $this->assertSame(1, Order::where('tenant_id', $this->tenant->id)->count());
    }
}
