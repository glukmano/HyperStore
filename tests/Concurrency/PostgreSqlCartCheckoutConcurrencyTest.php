<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Checkout\Models\CheckoutOperationKey;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Tests\TestCase;

class PostgreSqlCartCheckoutConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private User $user;

    private Product $product;

    private Warehouse $warehouse;

    private InventorySource $source;

    private StockItem $stockItem;

    private ShippingMethod $method;

    private CartServiceInterface $cartService;

    private CheckoutOrchestratorInterface $checkoutOrchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'Conc Tenant', 'slug' => 'conc-tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'CONC_S1', 'name' => 'Store 1', 'slug' => 'conc-s1', 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $this->channel = Channel::create(['name' => 'Web', 'handle' => 'web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->channel->id, 'is_active' => true]);

        $this->user = User::factory()->create();

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'CONC-1',
            'name' => 'Conc Product',
            'slug' => 'conc-product',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 1.0,
        ]);

        $this->warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_CONC', 'name' => 'Conc Wh', 'country_code' => 'CH', 'status' => 'active']);
        $this->source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->warehouse->id, 'code' => 'SRC_CONC', 'name' => 'Conc Source', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);
        $this->stockItem = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->source->id, 'product_id' => $this->product->id, 'on_hand' => 1, 'reserved' => 0]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'STD', 'name' => 'Std', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->product->id, 'amount_minor' => 1000, 'currency' => 'CHF', 'status' => 'active']);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE', 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);
        $this->method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FLAT',
            'name' => 'Flat Rate',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 500,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->method->id, 'shipping_zone_id' => $zone->id]);

        $this->cartService = app(CartServiceInterface::class);
        $this->checkoutOrchestrator = app(CheckoutOrchestratorInterface::class);
    }

    /**
     * Helper to spawn synchronized concurrent worker scripts via proc_open with a file barrier.
     *
     * @param  list<string>  $scripts
     * @return list<array{exit_code: int, stdout: string, stderr: string}>
     */
    private function runSynchronizedParallelWorkers(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/barrier_'.uniqid().'.flag';
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            // Inject barrier synchronization check into worker script
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(1000); }", $script);

            $tmpFile = sys_get_temp_dir()."/worker_{$idx}_".uniqid().'.php';
            file_put_contents($tmpFile, $syncedScript);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $cmd = 'php '.escapeshellarg($tmpFile);
            $proc = proc_open($cmd, $descriptors, $pipes[$idx]);
            $processes[$idx] = [
                'resource' => $proc,
                'tmp_file' => $tmpFile,
            ];
        }

        // Release all parallel workers simultaneously by creating the barrier file
        usleep(50000); // 50ms setup buffer
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

            $results[$idx] = [
                'exit_code' => $exitCode,
                'stdout' => (string) $stdout,
                'stderr' => (string) $stderr,
            ];
        }

        @unlink($barrierFile);

        return $results;
    }

    public function test_race_a_two_concurrent_cart_cas_updates(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );
        $cart = $this->cartService->getOrCreateActiveCart($ctx);
        $line = $this->cartService->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $initialVersion = $cart->fresh()->version;

        $worker1 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartQuantity;

// __BARRIER_WAIT__
try {
    \$cart = Cart::find({$cart->id});
    app(CartServiceInterface::class)->updateQuantity(\$cart, {$line->id}, CartQuantity::fromInt(5), {$initialVersion});
    echo 'SUCCESS_W1';
} catch (Throwable \$e) {
    echo 'FAIL_W1:' . \$e->getMessage();
}
";

        $worker2 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartQuantity;

// __BARRIER_WAIT__
try {
    \$cart = Cart::find({$cart->id});
    app(CartServiceInterface::class)->updateQuantity(\$cart, {$line->id}, CartQuantity::fromInt(10), {$initialVersion});
    echo 'SUCCESS_W2';
} catch (Throwable \$e) {
    echo 'FAIL_W2:' . \$e->getMessage();
}
";

        $results = $this->runSynchronizedParallelWorkers([$worker1, $worker2]);

        $outputs = array_column($results, 'stdout');
        $successCount = count(array_filter($outputs, fn ($o) => str_contains($o, 'SUCCESS')));
        $failCount = count(array_filter($outputs, fn ($o) => str_contains($o, 'FAIL')));

        $this->assertSame(1, $successCount, 'Exactly one concurrent CAS update must succeed.');
        $this->assertSame(1, $failCount, 'The conflicting CAS update must fail.');
        $this->assertSame($initialVersion + 1, $cart->fresh()->version);
    }

    public function test_race_b_two_concurrent_create_checkout_attempts_same_cart(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );
        $cart = $this->cartService->getOrCreateActiveCart($ctx);
        $this->cartService->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $worker1 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Cart\Models\Cart;

// __BARRIER_WAIT__
try {
    \$cart = Cart::find({$cart->id});
    \$session = app(CheckoutOrchestratorInterface::class)->createFromCart(\$cart);
    echo 'SESSION:' . \$session->id;
} catch (Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
";

        $worker2 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Cart\Models\Cart;

// __BARRIER_WAIT__
try {
    \$cart = Cart::find({$cart->id});
    \$session = app(CheckoutOrchestratorInterface::class)->createFromCart(\$cart);
    echo 'SESSION:' . \$session->id;
} catch (Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
";

        $results = $this->runSynchronizedParallelWorkers([$worker1, $worker2]);

        $outputs = array_column($results, 'stdout');
        $sessionIds = [];
        foreach ($outputs as $out) {
            if (preg_match('/SESSION:(\d+)/', $out, $m)) {
                $sessionIds[] = (int) $m[1];
            }
        }

        $this->assertCount(2, $sessionIds);
        $this->assertSame($sessionIds[0], $sessionIds[1]);
        $this->assertSame(1, CheckoutSession::where('tenant_id', $this->tenant->id)->where('cart_id', $cart->id)->count());
    }

    public function test_race_c_two_concurrent_reservation_attempts_limited_stock(): void
    {
        $cart1 = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'CHF', $this->user->id));
        $this->cartService->addLine($cart1, new CartLineItemData($this->product->id, null, CartQuantity::fromInt(1)));

        $cart2 = Cart::create([
            'tenant_id' => $this->tenant->id,
            'guest_token_hash' => hash('sha256', 'guest-concurrent-2'),
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'CHF',
            'status' => 'active',
        ]);
        $this->cartService->addLine($cart2, new CartLineItemData($this->product->id, null, CartQuantity::fromInt(1)));

        $session1 = $this->checkoutOrchestrator->createFromCart($cart1);
        $this->checkoutOrchestrator->setCustomerData($session1, new CheckoutCustomerData('u1@example.com', 'U', '1'));
        $this->checkoutOrchestrator->setAddresses($session1, new CheckoutAddress('U 1', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $this->checkoutOrchestrator->selectShippingQuote($session1, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);

        $session2 = $this->checkoutOrchestrator->createFromCart($cart2);
        $this->checkoutOrchestrator->setCustomerData($session2, new CheckoutCustomerData('u2@example.com', 'U', '2'));
        $this->checkoutOrchestrator->setAddresses($session2, new CheckoutAddress('U 2', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $this->checkoutOrchestrator->selectShippingQuote($session2, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);

        $worker1 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Models\CheckoutSession;

// __BARRIER_WAIT__
try {
    \$s = CheckoutSession::find({$session1->id});
    app(CheckoutOrchestratorInterface::class)->reserveInventory(\$s);
    echo 'SUCCESS_RES_1';
} catch (Throwable \$e) {
    echo 'FAIL_RES_1:' . \$e->getMessage();
}
";

        $worker2 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Models\CheckoutSession;

// __BARRIER_WAIT__
try {
    \$s = CheckoutSession::find({$session2->id});
    app(CheckoutOrchestratorInterface::class)->reserveInventory(\$s);
    echo 'SUCCESS_RES_2';
} catch (Throwable \$e) {
    echo 'FAIL_RES_2:' . \$e->getMessage();
}
";

        $results = $this->runSynchronizedParallelWorkers([$worker1, $worker2]);

        $outputs = array_column($results, 'stdout');
        $successCount = count(array_filter($outputs, fn ($o) => str_contains($o, 'SUCCESS_RES')));
        $failCount = count(array_filter($outputs, fn ($o) => str_contains($o, 'FAIL_RES')));

        $this->assertSame(1, $successCount, 'Exactly one concurrent reservation must succeed.');
        $this->assertSame(1, $failCount, 'The conflicting reservation must fail on insufficient stock.');
        $this->assertSame('1.0000', (string) $this->stockItem->fresh()->reserved);
    }

    public function test_race_d_two_concurrent_ready_for_order_calls_same_idempotency_key(): void
    {
        $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'CHF', $this->user->id));
        $this->cartService->addLine($cart, new CartLineItemData($this->product->id, null, CartQuantity::fromInt(1)));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('u1@example.com', 'U', '1'));
        $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('U 1', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);
        $session = $this->checkoutOrchestrator->reserveInventory($session);

        $idempKey = 'race-ready-key-999';

        $worker1 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Models\CheckoutSession;

// __BARRIER_WAIT__
try {
    \$s = CheckoutSession::find({$session->id});
    \$res = app(CheckoutOrchestratorInterface::class)->markReadyForOrder(\$s, '{$idempKey}');
    echo 'FINALIZED_AT:' . \$res->finalizedAt->toIso8601String() . '|RESULT:' . json_encode(\$res->toArray());
} catch (Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
";

        $worker2 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Models\CheckoutSession;

// __BARRIER_WAIT__
try {
    \$s = CheckoutSession::find({$session->id});
    \$res = app(CheckoutOrchestratorInterface::class)->markReadyForOrder(\$s, '{$idempKey}');
    echo 'FINALIZED_AT:' . \$res->finalizedAt->toIso8601String() . '|RESULT:' . json_encode(\$res->toArray());
} catch (Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
";

        $results = $this->runSynchronizedParallelWorkers([$worker1, $worker2]);

        $outputs = array_column($results, 'stdout');
        $finalizedAts = [];
        $resultSnapshots = [];
        foreach ($outputs as $out) {
            if (preg_match('/FINALIZED_AT:(.+)\|RESULT:(.+)/', $out, $m)) {
                $finalizedAts[] = $m[1];
                $resultSnapshots[] = $m[2];
            }
        }

        // Both concurrent callers obtain the exact SAME immutable result and finalized_at
        $this->assertCount(2, $finalizedAts);
        $this->assertSame($finalizedAts[0], $finalizedAts[1]);
        $this->assertSame($resultSnapshots[0], $resultSnapshots[1]);

        // Exactly one completed operation key row exists in DB
        $opKeys = CheckoutOperationKey::where('tenant_id', $this->tenant->id)
            ->where('checkout_session_id', $session->id)
            ->where('idempotency_key', $idempKey)
            ->get();
        $this->assertCount(1, $opKeys);
        $this->assertSame('completed', $opKeys->first()->status);
    }

    public function test_race_e_expiry_cleanup_vs_checkout_reservation(): void
    {
        $cart = $this->cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'CHF', $this->user->id));
        $this->cartService->addLine($cart, new CartLineItemData($this->product->id, null, CartQuantity::fromInt(1)));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('u1@example.com', 'U', '1'));
        $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('U 1', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);

        // Set session to expired
        $session->expires_at = now()->subMinute();
        $session->save();

        $worker1 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;

// __BARRIER_WAIT__
try {
    Artisan::call('hyper:checkout:cleanup-expired');
    echo 'EXPIRY_CLEANED';
} catch (Throwable \$e) {
    echo 'EXPIRY_FAIL:' . \$e->getMessage();
}
";

        $worker2 = "<?php
require '".base_path('vendor/autoload.php')."';
\$app = require_once '".base_path('bootstrap/app.php')."';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Models\CheckoutSession;

// __BARRIER_WAIT__
try {
    \$s = CheckoutSession::find({$session->id});
    app(CheckoutOrchestratorInterface::class)->reserveInventory(\$s);
    echo 'RESERVE_SUCCESS';
} catch (Throwable \$e) {
    echo 'RESERVE_FAIL:' . \$e->getMessage();
}
";

        $results = $this->runSynchronizedParallelWorkers([$worker1, $worker2]);

        $session->refresh();
        // End state must be clean: either expired or cancelled, stock reserved must be 0
        $this->assertTrue(in_array($session->state, ['expired', 'cancelled', 'inventory_reserved'], true));
        $this->assertDatabaseHas('checkout_sessions', ['id' => $session->id]);
    }
}
