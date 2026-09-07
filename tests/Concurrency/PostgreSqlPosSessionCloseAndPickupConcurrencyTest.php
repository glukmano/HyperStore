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
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Order\Enums\FulfillmentStatus;
use Modules\Order\Models\Order;
use Modules\POS\DTOs\PosTenderSelection;
use Modules\POS\Models\PosCashMovement;
use Modules\POS\Models\PosRegister;
use Modules\POS\Services\PosPickupService;
use Modules\POS\Services\PosRegisterSessionService;
use Modules\POS\Services\PosSaleOrchestrator;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Tests\TestCase;

/**
 * Pre-Production Readiness gate: the two Phase-22 acceptance items that
 * were intentionally postponed. Both must have real PostgreSQL
 * concurrency proof, per explicit Owner instruction.
 */
class PostgreSqlPosSessionCloseAndPickupConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private Store $store;

    private InventorySource $source;

    private User $cashier;

    private PosRegister $register;

    private Product $product;

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
            $this->markTestSkipped('This test requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $uid = uniqid();
        $this->tenant = Tenant::create(['name' => 'POS Close Conc Tenant', 'slug' => 'pos-close-conc-'.$uid, 'status' => 'active']);
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'PCC_S_'.$uid, 'name' => 'Store', 'slug' => 'pcc-s-'.$uid, 'status' => 'active']);
        $market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'PCC_M_'.$uid, 'name' => 'Market', 'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'is_active' => true]);
        MarketCurrency::create(['market_id' => $market->id, 'currency_code' => 'USD', 'is_default' => true]);
        $storeMarket = StoreMarket::create(['store_id' => $this->store->id, 'market_id' => $market->id, 'is_active' => true, 'is_default' => true]);

        $channel = Channel::create(['type' => 'pos', 'name' => 'POS', 'handle' => 'pcc-pos-'.$uid, 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $channel->id]);

        $this->cashier = User::factory()->create();
        StoreUser::create(['store_id' => $this->store->id, 'user_id' => $this->cashier->id, 'role' => 'cashier', 'is_active' => true]);

        $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'PCC_WH_'.$uid, 'name' => 'WH', 'country_code' => 'US', 'status' => 'active']);
        $this->source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'PCC_SRC_'.$uid, 'name' => 'Source', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        $this->register = PosRegister::create([
            'tenant_id' => $this->tenant->id,
            'store_market_id' => $storeMarket->id,
            'channel_id' => $channel->id,
            'inventory_source_id' => $this->source->id,
            'code' => 'PCCREG',
            'name' => 'Conc Register',
            'status' => 'active',
        ]);

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'PCC_TAX_'.$uid, 'name' => 'Tax', 'is_default' => true]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'PCC-SKU-'.$uid,
            'name' => 'Conc Product',
            'slug' => 'pcc-product-'.$uid,
            'product_type' => 'physical',
            'status' => 'active',
        ]);
        ProductStoreListing::create(['product_id' => $this->product->id, 'store_id' => $this->store->id, 'status' => 'published']);
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->source->id, 'product_id' => $this->product->id, 'on_hand' => 100, 'reserved' => 0]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'PCC_PB_'.$uid, 'name' => 'PB', 'currency' => 'USD', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->product->id, 'amount_minor' => 1000, 'currency' => 'USD', 'status' => 'active']);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'PCC_ZONE_'.$uid, 'name' => 'Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'US']);
        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PCC_FLAT_'.$uid,
            'name' => 'Flat',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'USD',
            'base_amount' => 0,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);
    }

    /**
     * @param  list<string>  $scripts
     * @return list<array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/pos_close_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $synced = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_pos_close_{$idx}_".uniqid().'.php';
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

    /**
     * A worker records a cash movement while a second worker simultaneously
     * closes the same RegisterSession. The RegisterSession row lock shared
     * by PosCashMovementService::record() and PosRegisterSessionService::
     * close() must serialize these so no inconsistent state results:
     * either the movement lands and is included in the closing snapshot,
     * or it is cleanly rejected because the session already closed —
     * never a movement silently recorded against an already-closed drawer.
     */
    public function test_recording_a_cash_movement_races_closing_the_session_with_no_inconsistent_state(): void
    {
        $session = app(PosRegisterSessionService::class)->open($this->register, $this->cashier->id, 'USD', 10000);
        $sessionId = $session->id;
        $cashierId = $this->cashier->id;
        $movementSourceUuid = 'race-movement-'.uniqid();
        $bootstrap = $this->bootstrapScript();

        $closeWorker = "{$bootstrap}
use Modules\\POS\\Models\\PosRegisterSession;
use Modules\\POS\\Services\\PosRegisterSessionService;

// __BARRIER_WAIT__
try {
    \$session = PosRegisterSession::find({$sessionId});
    app(PosRegisterSessionService::class)->close(\$session, 10000, {$cashierId});
    echo 'CLOSED';
} catch (\\Throwable \$e) {
    echo 'CLOSE_FAIL:' . get_class(\$e);
}
";

        $movementWorker = "{$bootstrap}
use Modules\\POS\\Models\\PosRegisterSession;
use Modules\\POS\\Services\\PosCashMovementService;
use Modules\\POS\\Enums\\CashMovementType;

// __BARRIER_WAIT__
try {
    \$session = PosRegisterSession::find({$sessionId});
    app(PosCashMovementService::class)->record(
        \$session,
        CashMovementType::PAID_IN,
        500,
        'race_test',
        '{$movementSourceUuid}',
        {$cashierId},
        'race test movement'
    );
    echo 'MOVEMENT_RECORDED';
} catch (\\Modules\\POS\\Exceptions\\PosRegisterSessionClosedException \$e) {
    echo 'MOVEMENT_REJECTED_CLOSED';
} catch (\\Throwable \$e) {
    echo 'MOVEMENT_FAIL:' . \$e->getMessage();
}
";

        $results = $this->executeConcurrently([$closeWorker, $movementWorker]);
        $outputs = array_column($results, 'stdout');

        // Both outcomes must resolve cleanly — no PHP fatal/unexpected exception class.
        $this->assertMatchesRegularExpression('/CLOSED|CLOSE_FAIL/', $outputs[0]);
        $this->assertMatchesRegularExpression('/MOVEMENT_RECORDED|MOVEMENT_REJECTED_CLOSED/', $outputs[1]);

        $session->refresh();
        $this->assertSame('closed', $session->status->value, 'The session must end up closed regardless of race outcome.');

        // The core invariant: the closing snapshot always exactly equals a
        // fresh, independent recomputation of every movement that actually
        // exists — no drift possible, whichever operation won the race.
        $actualSum = (int) PosCashMovement::where('register_session_id', $sessionId)->sum('amount_minor');
        $this->assertSame($actualSum, $session->closing_cash_expected_minor, 'closing_cash_expected_minor must exactly equal the true sum of movements — no race-induced drift.');

        $movementRecorded = str_contains($outputs[1], 'MOVEMENT_RECORDED');
        $movementCount = PosCashMovement::where('source_type', 'race_test')->where('source_uuid', $movementSourceUuid)->count();

        if ($movementRecorded) {
            $this->assertSame(1, $movementCount, 'A reported success must correspond to exactly one real movement row.');
        } else {
            $this->assertSame(0, $movementCount, 'A rejected movement must never partially exist.');
        }
    }

    /**
     * Owner Delta §7 / Pre-Production gate: two staff actions racing to
     * mark the same READY_FOR_PICKUP order as PICKED_UP must result in
     * exactly one successful transition — the other a clean, idempotent
     * no-op — never a double transition or inconsistent fulfillment_status.
     */
    public function test_two_staff_racing_to_confirm_the_same_pickup_result_in_exactly_one_transition(): void
    {
        $session = app(PosRegisterSessionService::class)->open($this->register, $this->cashier->id, 'USD', 10000);
        $orchestrator = app(PosSaleOrchestrator::class);
        $cartService = app(CartServiceInterface::class);

        $cart = $orchestrator->getOrCreateCart($session, null, 'pickup-race-guest');
        $cartService->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
        ));
        $result = $orchestrator->completeSale($session, $cart, 'pickup-race-sale', new PosTenderSelection(cashAmountMinor: 1000));
        $order = $result->order;

        app(PosPickupService::class)->markReadyForPickup($order);
        $this->assertSame(FulfillmentStatus::READY_FOR_PICKUP->value, $order->fresh()->fulfillment_status);

        $orderId = $order->id;
        $bootstrap = $this->bootstrapScript();

        $worker = "{$bootstrap}
use Modules\\Order\\Models\\Order;
use Modules\\POS\\Services\\PosPickupService;

// __BARRIER_WAIT__
try {
    \$order = Order::find({$orderId});
    \$transitioned = app(PosPickupService::class)->confirmPickedUp(\$order);
    echo \$transitioned ? 'TRANSITIONED' : 'NOOP';
} catch (\\Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
";

        $results = $this->executeConcurrently([$worker, $worker]);
        $outputs = array_column($results, 'stdout');

        $transitionedCount = count(array_filter($outputs, fn ($o) => str_contains($o, 'TRANSITIONED')));
        $noopCount = count(array_filter($outputs, fn ($o) => str_contains($o, 'NOOP')));

        $this->assertSame(1, $transitionedCount, 'Exactly one worker must perform the real transition.');
        $this->assertSame(1, $noopCount, 'The other worker must see an already-picked-up order and no-op idempotently.');
        $this->assertSame(FulfillmentStatus::PICKED_UP->value, $order->fresh()->fulfillment_status);
    }
}
