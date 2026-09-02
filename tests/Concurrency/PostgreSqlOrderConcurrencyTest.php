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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;
use Modules\Order\Contracts\OrderCancellationServiceInterface;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\Contracts\OrderNumberGeneratorInterface;
use Modules\Order\Contracts\OrderStateMachineServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderOperationKey;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Tests\TestCase;

class PostgreSqlOrderConcurrencyTest extends TestCase
{
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

    private InventoryReservationServiceInterface $invService;

    private OrderCreationServiceInterface $creationService;

    private OrderCancellationServiceInterface $cancellationService;

    private OrderStateMachineServiceInterface $stateMachine;

    private OrderNumberGeneratorInterface $numberGenerator;

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

        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $uid = uniqid();
        $this->tenant = Tenant::create(['name' => 'Conc Tenant', 'slug' => 'conc-tenant-'.$uid, 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store 1', 'slug' => 'ord-s1-'.$uid, 'status' => 'active']);
        $this->market = Market::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CH_'.$uid,
            'name' => 'Switzerland',
            'default_currency_code' => 'CHF',
            'default_locale_code' => 'en',
            'timezone' => 'Europe/Zurich',
            'is_active' => true,
        ]);
        $this->channel = Channel::firstOrCreate(['handle' => 'web'], ['name' => 'Web', 'is_active' => true]);
        StoreChannel::firstOrCreate(['store_id' => $this->store->id, 'channel_id' => $this->channel->id], ['is_active' => true]);

        $this->user = User::factory()->create();

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX_'.$uid, 'name' => 'Standard Tax', 'is_default' => true]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'ORD-PROD-'.$uid,
            'name' => 'Order Concurrency Product',
            'slug' => 'ord-product-'.$uid,
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 1.0,
        ]);

        $this->warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_ORD_'.$uid, 'name' => 'Order Wh', 'country_code' => 'CH', 'status' => 'active']);
        $this->source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->warehouse->id, 'code' => 'SRC_ORD_'.$uid, 'name' => 'Order Source', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);
        $this->stockItem = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->source->id, 'product_id' => $this->product->id, 'on_hand' => 100, 'reserved' => 0]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'STD', 'name' => 'Std', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->product->id, 'amount_minor' => 1000, 'currency' => 'CHF', 'status' => 'active']);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE_'.$uid, 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);
        $this->method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FLAT_'.$uid,
            'name' => 'Flat Rate',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 500,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->method->id, 'shipping_zone_id' => $zone->id]);

        $this->cartService = app(CartServiceInterface::class);
        $this->checkoutOrchestrator = app(CheckoutOrchestratorInterface::class);
        $this->invService = app(InventoryReservationServiceInterface::class);
        $this->creationService = app(OrderCreationServiceInterface::class);
        $this->cancellationService = app(OrderCancellationServiceInterface::class);
        $this->stateMachine = app(OrderStateMachineServiceInterface::class);
        $this->numberGenerator = app(OrderNumberGeneratorInterface::class);
    }

    private function runSynchronizedParallelWorkers(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/barrier_ord_'.uniqid().'.flag';
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(1000); }", $script);

            $tmpFile = sys_get_temp_dir()."/worker_ord_{$idx}_".uniqid().'.php';
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

    private function createReadyCheckout(): CheckoutSession
    {
        $cart = $this->cartService->getOrCreateActiveCart(new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        ));
        $this->cartService->addLine($cart, new CartLineItemData($this->product->id, null, CartQuantity::fromInt(1)));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('conc@example.com', 'Conc', 'User'));
        $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('Conc User', ['Bahnhofstrasse 1'], 'Zurich', 'CH', postalCode: '8001'));
        $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);
        $session = $this->checkoutOrchestrator->reserveInventory($session);

        $this->checkoutOrchestrator->markReadyForOrder($session);

        /** @var CheckoutSession $fresh */
        $fresh = CheckoutSession::find($session->id);

        return $fresh;
    }

    // ---------------------------------------------------------------------------
    // Race A: Same ready Checkout, two concurrent Order creations with idempotency key
    // ---------------------------------------------------------------------------
    public function test_race_a_two_concurrent_order_creations_with_idempotency_key(): void
    {
        $checkout = $this->createReadyCheckout();
        $idemKey = 'idem-race-a-'.uniqid();
        $basePath = base_path();

        $workerTemplate = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Enums\OrderActorType;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

// __BARRIER_WAIT__

try {
    \$service = app(OrderCreationServiceInterface::class);
    \$result = \$service->createFromCheckout(new OrderCreationDTO(
        tenantId: __TENANT_ID__,
        checkoutId: __CHECKOUT_ID__,
        idempotencyKey: '__IDEM_KEY__',
        actorType: OrderActorType::CUSTOMER,
        actorId: __USER_ID__
    ));

    echo json_encode([
        'status' => 'success',
        'order_id' => \$result->order->id,
        'order_number' => \$result->order->order_number,
        'is_replay' => \$result->isReplay,
    ]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'message' => \$e->getMessage(), 'class' => get_class(\$e)]);
}
PHP;

        $script1 = str_replace(['__TENANT_ID__', '__CHECKOUT_ID__', '__IDEM_KEY__', '__USER_ID__'], [$this->tenant->id, $checkout->id, $idemKey, $this->user->id], $workerTemplate);
        $script2 = str_replace(['__TENANT_ID__', '__CHECKOUT_ID__', '__IDEM_KEY__', '__USER_ID__'], [$this->tenant->id, $checkout->id, $idemKey, $this->user->id], $workerTemplate);

        $results = $this->runSynchronizedParallelWorkers([$script1, $script2]);

        $out1 = json_decode($results[0]['stdout'], true);
        $out2 = json_decode($results[1]['stdout'], true);

        $this->assertSame('success', $out1['status'] ?? null, "W1 failed: {$results[0]['stdout']} {$results[0]['stderr']}");
        $this->assertSame('success', $out2['status'] ?? null, "W2 failed: {$results[1]['stdout']} {$results[1]['stderr']}");

        $this->assertSame(1, Order::where('checkout_id', $checkout->id)->count());
        $this->assertSame($out1['order_id'], $out2['order_id']);

        $replays = [$out1['is_replay'], $out2['is_replay']];
        $this->assertTrue(in_array(false, $replays, true));
        $this->assertTrue(in_array(true, $replays, true));
    }

    // ---------------------------------------------------------------------------
    // Race B: Same ready Checkout, NO idempotency key -> DB UNIQUE constraint protects
    // ---------------------------------------------------------------------------
    public function test_race_b_two_concurrent_order_creations_no_idempotency_key(): void
    {
        $checkout = $this->createReadyCheckout();
        $basePath = base_path();

        $workerTemplate = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Enums\OrderActorType;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

// __BARRIER_WAIT__

try {
    \$service = app(OrderCreationServiceInterface::class);
    \$result = \$service->createFromCheckout(new OrderCreationDTO(
        tenantId: __TENANT_ID__,
        checkoutId: __CHECKOUT_ID__,
        idempotencyKey: null,
        actorType: OrderActorType::CUSTOMER,
        actorId: __USER_ID__
    ));

    echo json_encode([
        'status' => 'success',
        'order_id' => \$result->order->id,
        'is_replay' => \$result->isReplay,
    ]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'message' => \$e->getMessage(), 'class' => get_class(\$e)]);
}
PHP;

        $script1 = str_replace(['__TENANT_ID__', '__CHECKOUT_ID__', '__USER_ID__'], [$this->tenant->id, $checkout->id, $this->user->id], $workerTemplate);
        $script2 = str_replace(['__TENANT_ID__', '__CHECKOUT_ID__', '__USER_ID__'], [$this->tenant->id, $checkout->id, $this->user->id], $workerTemplate);

        $results = $this->runSynchronizedParallelWorkers([$script1, $script2]);

        $out1 = json_decode($results[0]['stdout'], true);
        $out2 = json_decode($results[1]['stdout'], true);

        $this->assertSame(1, Order::where('checkout_id', $checkout->id)->count());
        $this->assertSame('success', $out1['status'] ?? null);
        $this->assertSame('success', $out2['status'] ?? null);
        $this->assertSame($out1['order_id'], $out2['order_id']);
    }

    // ---------------------------------------------------------------------------
    // Race C: TRUE MULTI-PROCESS RACE - Same idempotency key + same fingerprint
    // ---------------------------------------------------------------------------
    public function test_race_c_true_process_race_same_idempotency_key_same_fingerprint(): void
    {
        $checkout = $this->createReadyCheckout();
        $idemKey = 'race-c-key-'.uniqid();
        $basePath = base_path();

        $workerTemplate = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Enums\OrderActorType;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

// __BARRIER_WAIT__

try {
    \$service = app(OrderCreationServiceInterface::class);
    \$result = \$service->createFromCheckout(new OrderCreationDTO(
        tenantId: __TENANT_ID__,
        checkoutId: __CHECKOUT_ID__,
        idempotencyKey: '__IDEM_KEY__',
        actorType: OrderActorType::CUSTOMER,
        actorId: __USER_ID__
    ));

    echo json_encode([
        'status' => 'success',
        'order_id' => \$result->order->id,
        'order_number' => \$result->order->order_number,
        'is_replay' => \$result->isReplay,
    ]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'message' => \$e->getMessage(), 'class' => get_class(\$e)]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__CHECKOUT_ID__', '__IDEM_KEY__', '__USER_ID__'], [$this->tenant->id, $checkout->id, $idemKey, $this->user->id], $workerTemplate);
        $s2 = str_replace(['__TENANT_ID__', '__CHECKOUT_ID__', '__IDEM_KEY__', '__USER_ID__'], [$this->tenant->id, $checkout->id, $idemKey, $this->user->id], $workerTemplate);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        $out1 = json_decode($results[0]['stdout'], true);
        $out2 = json_decode($results[1]['stdout'], true);

        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertSame(0, $results[1]['exit_code']);
        $this->assertSame('success', $out1['status']);
        $this->assertSame('success', $out2['status']);

        // Exactly 1 Order created in DB
        $this->assertSame(1, Order::where('checkout_id', $checkout->id)->count());
        $this->assertSame($out1['order_id'], $out2['order_id']);

        // Exactly one first execution and one replay
        $replays = [$out1['is_replay'], $out2['is_replay']];
        $this->assertTrue(in_array(false, $replays, true));
        $this->assertTrue(in_array(true, $replays, true));

        // One completed operation key
        $opKey = OrderOperationKey::where('tenant_id', $this->tenant->id)
            ->where('checkout_id', $checkout->id)
            ->where('idempotency_key', $idemKey)
            ->first();
        $this->assertNotNull($opKey);
        $this->assertSame('completed', $opKey->status);
    }

    // ---------------------------------------------------------------------------
    // Race D: TRUE MULTI-PROCESS RACE - Same idempotency key + different fingerprints
    // ---------------------------------------------------------------------------
    public function test_race_d_true_process_race_same_key_different_fingerprints(): void
    {
        $checkout = $this->createReadyCheckout();
        $idemKey = 'race-d-key-'.uniqid();
        $basePath = base_path();

        $workerTemplate = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Enums\OrderActorType;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

// __BARRIER_WAIT__

try {
    \$service = app(OrderCreationServiceInterface::class);
    \$result = \$service->createFromCheckout(new OrderCreationDTO(
        tenantId: __TENANT_ID__,
        checkoutId: __CHECKOUT_ID__,
        idempotencyKey: '__IDEM_KEY__',
        actorType: __ACTOR_TYPE__,
        actorId: __ACTOR_ID__
    ));

    echo json_encode([
        'status' => 'success',
        'order_id' => \$result->order->id,
    ]);
} catch (\Modules\Order\Exceptions\IdempotencyFingerprintMismatchException \$e) {
    echo json_encode(['status' => 'fingerprint_mismatch', 'message' => \$e->getMessage()]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'message' => \$e->getMessage(), 'class' => get_class(\$e)]);
}
PHP;

        // W1: Customer
        $s1 = str_replace(
            ['__TENANT_ID__', '__CHECKOUT_ID__', '__IDEM_KEY__', '__ACTOR_TYPE__', '__ACTOR_ID__'],
            [$this->tenant->id, $checkout->id, $idemKey, 'OrderActorType::CUSTOMER', (string) $this->user->id],
            $workerTemplate
        );
        // W2: Staff (different actor type and ID -> different fingerprint)
        $s2 = str_replace(
            ['__TENANT_ID__', '__CHECKOUT_ID__', '__IDEM_KEY__', '__ACTOR_TYPE__', '__ACTOR_ID__'],
            [$this->tenant->id, $checkout->id, $idemKey, 'OrderActorType::STAFF', '9999'],
            $workerTemplate
        );

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        $out1 = json_decode($results[0]['stdout'], true);
        $out2 = json_decode($results[1]['stdout'], true);

        $statuses = [$out1['status'], $out2['status']];
        $this->assertContains('success', $statuses);
        $this->assertContains('fingerprint_mismatch', $statuses);

        // Exactly one Order exists
        $this->assertSame(1, Order::where('checkout_id', $checkout->id)->count());

        // Operation key exists and matches winner's hash
        $opKey = OrderOperationKey::where('tenant_id', $this->tenant->id)
            ->where('checkout_id', $checkout->id)
            ->where('idempotency_key', $idemKey)
            ->first();
        $this->assertNotNull($opKey);
        $this->assertSame('completed', $opKey->status);
    }

    // ---------------------------------------------------------------------------
    // Race E: High-concurrency atomic order number generation
    // ---------------------------------------------------------------------------
    public function test_race_e_concurrent_order_number_generation_no_duplicates(): void
    {
        $basePath = base_path();
        $workerTemplate = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Order\Contracts\OrderNumberGeneratorInterface;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

// __BARRIER_WAIT__

\$gen = app(OrderNumberGeneratorInterface::class);
\$numbers = [];
for (\$i = 0; \$i < 5; \$i++) {
    \$numbers[] = \$gen->generate(__TENANT_ID__, new DateTimeZone('Europe/Zurich'));
}

echo json_encode(\$numbers);
PHP;

        $scripts = [];
        for ($w = 0; $w < 4; $w++) {
            $scripts[] = str_replace('__TENANT_ID__', (string) $this->tenant->id, $workerTemplate);
        }

        $results = $this->runSynchronizedParallelWorkers($scripts);

        $allNumbers = [];
        foreach ($results as $res) {
            $nums = json_decode($res['stdout'], true);
            $this->assertIsArray($nums, "Worker failed: {$res['stdout']} {$res['stderr']}");
            foreach ($nums as $n) {
                $allNumbers[] = $n;
            }
        }

        $this->assertCount(20, $allNumbers);
        $uniqueNumbers = array_unique($allNumbers);
        $this->assertCount(20, $uniqueNumbers, 'Duplicate order numbers detected under concurrent generation!');
    }

    // ---------------------------------------------------------------------------
    // Race F: STRENGTHENED - Concurrent Order Cancellation vs Order Transition
    // ---------------------------------------------------------------------------
    public function test_race_f_strengthened_concurrent_cancel_vs_transition(): void
    {
        $checkout = $this->createReadyCheckout();
        $order = $this->creationService->createFromCheckout(new OrderCreationDTO(
            tenantId: $this->tenant->id,
            checkoutId: $checkout->id,
        ))->order;

        $basePath = base_path();

        $workerCancel = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Order\Contracts\OrderCancellationServiceInterface;
use Modules\Order\Models\Order;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

// __BARRIER_WAIT__

try {
    \$order = Order::find(__ORDER_ID__);
    \$service = app(OrderCancellationServiceInterface::class);
    \$service->cancel(\$order, 'Concurrent cancel');
    echo json_encode(['status' => 'cancelled']);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
}
PHP;

        $workerTransition = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Order\Contracts\OrderStateMachineServiceInterface;
use Modules\Order\DTOs\OrderTransitionDTO;
use Modules\Order\Enums\StatusDimension;
use Modules\Order\Models\Order;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

// __BARRIER_WAIT__

try {
    \$order = Order::find(__ORDER_ID__);
    \$service = app(OrderStateMachineServiceInterface::class);
    \$service->transition(\$order, new OrderTransitionDTO(
        fromStatus: 'placed',
        toStatus: 'confirmed',
        dimension: StatusDimension::ORDER,
        reason: 'Concurrent confirm'
    ));
    echo json_encode(['status' => 'confirmed']);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace('__ORDER_ID__', (string) $order->id, $workerCancel);
        $s2 = str_replace('__ORDER_ID__', (string) $order->id, $workerTransition);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertSame(0, $results[1]['exit_code']);

        $outCancel = json_decode($results[0]['stdout'], true);
        $outTransition = json_decode($results[1]['stdout'], true);

        $finalOrder = $order->fresh();

        // Exact Outcome Verification
        if ($outCancel['status'] === 'cancelled' && $outTransition['status'] === 'error') {
            // Outcome A: Cancel won first -> transition failed stale -> final cancelled
            $this->assertStringContainsString('STALE_ORDER_TRANSITION', $outTransition['msg']);
            $this->assertSame(2, $finalOrder->version);
            $historyTo = $finalOrder->statusHistory()->orderBy('id')->pluck('to_status')->all();
            $this->assertSame(['placed', 'cancelled'], $historyTo);
        } elseif ($outTransition['status'] === 'confirmed' && $outCancel['status'] === 'cancelled') {
            // Outcome B: Transition won first -> cancel succeeded from confirmed -> final cancelled
            $this->assertSame(3, $finalOrder->version);
            $historyTo = $finalOrder->statusHistory()->orderBy('id')->pluck('to_status')->all();
            $this->assertSame(['placed', 'confirmed', 'cancelled'], $historyTo);
        } else {
            $this->fail('Unexpected Race F outcome: Cancel: '.json_encode($outCancel).', Transition: '.json_encode($outTransition).", Final Order status: {$finalOrder->order_status}");
        }

        $this->assertSame('cancelled', $finalOrder->order_status);
        $this->assertSame('cancelled', $finalOrder->fulfillment_status);
        $this->assertSame(0, bccomp((string) $this->stockItem->fresh()->reserved, '0.0000', 4));
    }

    // ---------------------------------------------------------------------------
    // Race G: Two concurrent cancellations release stock exactly once
    // ---------------------------------------------------------------------------
    public function test_race_g_two_concurrent_cancellations_exact_once_stock_release(): void
    {
        $resKey = 'race-g-res-'.uniqid();
        $this->invService->reserve($this->tenant->id, $resKey, $this->product->id, null, Quantity::fromString('5.0000'), new InventoryContext($this->tenant->id), 60);

        $cart = $this->cartService->getOrCreateActiveCart(new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        ));
        $checkout = CheckoutSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'CHF',
            'locale' => 'en',
            'state' => 'ready_for_order',
            'customer_data' => ['email' => 'raceg@example.com'],
            'shipping_address' => ['country_code' => 'CH'],
            'billing_address' => ['country_code' => 'CH'],
            'ready_snapshot' => [
                'context' => [
                    'store_id' => $this->store->id,
                    'market_id' => $this->market->id,
                    'channel_id' => $this->channel->id,
                    'currency' => 'CHF',
                    'locale' => 'en',
                ],
                'totals' => [
                    'merchandise_subtotal' => 5000,
                    'line_discounts' => 0,
                    'cart_discounts' => 0,
                    'shipping_original' => 0,
                    'shipping_discount' => 0,
                    'shipping_final' => 0,
                    'tax_total' => 0,
                    'grand_total' => 5000,
                    'currency' => 'CHF',
                ],
                'lines' => [[
                    'cart_line_id' => 7701,
                    'product_id' => $this->product->id,
                    'sku_snapshot' => 'RACE-G',
                    'name_snapshot' => 'Race G Product',
                    'product_type_snapshot' => 'physical',
                    'quantity' => '5.00000000',
                ]],
                'pricing_snapshot' => [
                    'lines' => [[
                        'cart_line_id' => 7701,
                        'product_id' => $this->product->id,
                        'variant_id' => null,
                        'quantity' => '5.00000000',
                        'unit_price_minor' => 1000,
                        'merchandise_line_subtotal_minor' => 5000,
                        'line_total_minor' => 5000,
                        'line_discount_minor' => 0,
                        'tax_minor' => 0,
                        'tax_class_id' => null,
                        'tax_rate_percent' => null,
                        'currency' => 'CHF',
                    ]],
                    'subtotal_minor' => 5000,
                    'currency' => 'CHF',
                ],
                'customer_data' => ['email' => 'raceg@example.com'],
                'reservation_references' => [
                    ['reservation_key' => $resKey, 'product_id' => $this->product->id, 'quantity' => '5.00000000'],
                ],
            ],
            'evaluated_cart_version' => 1,
            'version' => 1,
            'expires_at' => now()->addHour(),
        ]);

        $order = $this->creationService->createFromCheckout(new OrderCreationDTO(
            tenantId: $this->tenant->id,
            checkoutId: $checkout->id,
        ))->order;

        $basePath = base_path();
        $idemKey = 'race-g-cancel-idem-'.uniqid();

        $workerCancel = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Order\Contracts\OrderCancellationServiceInterface;
use Modules\Order\Models\Order;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

// __BARRIER_WAIT__

try {
    \$order = Order::find(__ORDER_ID__);
    \$service = app(OrderCancellationServiceInterface::class);
    \$service->cancel(\$order, 'Concurrent cancel test', idempotencyKey: '__IDEM_KEY__');
    echo json_encode(['status' => 'cancelled']);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__ORDER_ID__', '__IDEM_KEY__'], [(string) $order->id, $idemKey], $workerCancel);
        $s2 = str_replace(['__ORDER_ID__', '__IDEM_KEY__'], [(string) $order->id, $idemKey], $workerCancel);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        $this->assertSame(0, bccomp((string) $this->stockItem->fresh()->reserved, '0.0000', 4));
        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame('released', InventoryReservation::where('reservation_key', $resKey)->value('status'));
    }

    // ---------------------------------------------------------------------------
    // Race H: TRUE TWO-PROCESS OS RACE - Adoption failure boundary under concurrent Inventory release
    // ---------------------------------------------------------------------------
    public function test_race_h_true_two_process_race_adoption_failure_leaves_zero_committed_orders(): void
    {
        $resKeyA = 'race-h-res-a-'.uniqid();
        $resKeyB = 'race-h-res-b-'.uniqid();

        // 1. Both reservations start active via production inventory reserve()
        $this->invService->reserve($this->tenant->id, $resKeyA, $this->product->id, null, Quantity::fromString('1.0000'), new InventoryContext($this->tenant->id), 60);
        $this->invService->reserve($this->tenant->id, $resKeyB, $this->product->id, null, Quantity::fromString('1.0000'), new InventoryContext($this->tenant->id), 60);

        $this->assertSame('2.0000', (string) $this->stockItem->fresh()->reserved);

        $cart = $this->cartService->getOrCreateActiveCart(new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        ));

        $checkout = CheckoutSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'CHF',
            'locale' => 'en',
            'state' => 'ready_for_order',
            'customer_data' => ['email' => 'raceh@example.com'],
            'shipping_address' => ['country_code' => 'CH'],
            'billing_address' => ['country_code' => 'CH'],
            'ready_snapshot' => [
                'context' => [
                    'store_id' => $this->store->id,
                    'market_id' => $this->market->id,
                    'channel_id' => $this->channel->id,
                    'currency' => 'CHF',
                    'locale' => 'en',
                ],
                'totals' => [
                    'merchandise_subtotal' => 2000,
                    'line_discounts' => 0,
                    'cart_discounts' => 0,
                    'shipping_original' => 0,
                    'shipping_discount' => 0,
                    'shipping_final' => 0,
                    'tax_total' => 0,
                    'grand_total' => 2000,
                    'currency' => 'CHF',
                ],
                'lines' => [[
                    'cart_line_id' => 8801,
                    'product_id' => $this->product->id,
                    'sku_snapshot' => 'RACE-H',
                    'name_snapshot' => 'Race H Product',
                    'product_type_snapshot' => 'physical',
                    'quantity' => '2.00000000',
                ]],
                'pricing_snapshot' => [
                    'lines' => [[
                        'cart_line_id' => 8801,
                        'product_id' => $this->product->id,
                        'variant_id' => null,
                        'quantity' => '2.00000000',
                        'unit_price_minor' => 1000,
                        'merchandise_line_subtotal_minor' => 2000,
                        'line_total_minor' => 2000,
                        'line_discount_minor' => 0,
                        'tax_minor' => 0,
                        'tax_class_id' => null,
                        'tax_rate_percent' => null,
                        'currency' => 'CHF',
                    ]],
                    'subtotal_minor' => 2000,
                    'currency' => 'CHF',
                ],
                'customer_data' => ['email' => 'raceh@example.com'],
                'reservation_references' => [
                    ['reservation_key' => $resKeyA, 'product_id' => $this->product->id, 'quantity' => '1.00000000'],
                    ['reservation_key' => $resKeyB, 'product_id' => $this->product->id, 'quantity' => '1.00000000'],
                ],
            ],
            'evaluated_cart_version' => 1,
            'version' => 1,
            'expires_at' => now()->addHour(),
        ]);

        $basePath = base_path();
        $idemKey = 'race-h-key-'.uniqid();

        $pauseFlag = sys_get_temp_dir().'/race_h_pause_'.$resKeyB;
        $reachedFlag = sys_get_temp_dir().'/race_h_reached_'.$resKeyB;
        @unlink($pauseFlag);
        @unlink($reachedFlag);
        touch($pauseFlag);

        // Worker 1 (Order Worker): Creates order, adopts Res A, pauses at barrier before Res B
        $workerOrderScript = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Order\Contracts\OrderCreationConcurrencyBarrierInterface;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Exceptions\ReservationAdoptionFailedException;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

class DeterministicOrderCreationBarrier implements OrderCreationConcurrencyBarrierInterface
{
    public function beforeReservationAdoption(int \$tenantId, string \$reservationKey): void
    {
        \$pauseFlag = sys_get_temp_dir() . '/race_h_pause_' . \$reservationKey;
        if (file_exists(\$pauseFlag)) {
            \$reachedFlag = sys_get_temp_dir() . '/race_h_reached_' . \$reservationKey;
            touch(\$reachedFlag);
            for (\$i = 0; \$i < 5000; \$i++) {
                clearstatcache(true, \$pauseFlag);
                if (! file_exists(\$pauseFlag)) {
                    break;
                }
                usleep(1000);
            }
        }
    }
}

\$app->singleton(OrderCreationConcurrencyBarrierInterface::class, DeterministicOrderCreationBarrier::class);

// __BARRIER_WAIT__

try {
    \$service = app(OrderCreationServiceInterface::class);
    \$service->createFromCheckout(new OrderCreationDTO(
        tenantId: __TENANT_ID__,
        checkoutId: __CHECKOUT_ID__,
        idempotencyKey: '__IDEM_KEY__'
    ));
    echo json_encode(['status' => 'unexpected_success']);
} catch (ReservationAdoptionFailedException \$e) {
    echo json_encode(['status' => 'adoption_failed', 'msg' => \$e->getMessage()]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
}
PHP;

        // Worker 2 (Inventory Worker): Waits for Worker 1 to reach Res B barrier, releases Res B using production Inventory service, unpauses Worker 1
        $workerInvScript = <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Modules\Inventory\Contracts\InventoryReservationServiceInterface;

config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'hyperstore', 'database.connections.pgsql.username' => 'lukman', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 5432]);
DB::purge('pgsql');

// __BARRIER_WAIT__

\$reachedFlag = '{$reachedFlag}';
\$pauseFlag = '{$pauseFlag}';

// Wait until Order worker adopts Res A and pauses at Res B (bounded to 5 seconds)
for (\$waitIdx = 0; \$waitIdx < 5000; \$waitIdx++) {
    clearstatcache(true, \$reachedFlag);
    if (file_exists(\$reachedFlag)) {
        break;
    }
    usleep(1000);
}

// Competing production Inventory release on Res B
try {
    \$invService = app(InventoryReservationServiceInterface::class);
    \$released = \$invService->release(__TENANT_ID__, '__RES_KEY_B__');
    echo json_encode(['status' => 'released', 'result' => \$released]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
} finally {
    // Unpause Order worker so it attempts to adopt now-released Res B
    @unlink(\$pauseFlag);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__CHECKOUT_ID__', '__IDEM_KEY__'], [$this->tenant->id, $checkout->id, $idemKey], $workerOrderScript);
        $s2 = str_replace(['__TENANT_ID__', '__RES_KEY_B__'], [$this->tenant->id, $resKeyB], $workerInvScript);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        @unlink($pauseFlag);
        @unlink($reachedFlag);

        $out1 = json_decode($results[0]['stdout'], true);
        $out2 = json_decode($results[1]['stdout'], true);

        $this->assertSame('released', $out2['status'], "Worker 2 failed: {$results[1]['stdout']} {$results[1]['stderr']}");
        $this->assertSame('adoption_failed', $out1['status'], "Worker 1 failed: {$results[0]['stdout']} {$results[0]['stderr']}");

        // Final DB Invariants: 0 Orders for checkout, 0 OrderItems
        $this->assertSame(0, Order::where('checkout_id', $checkout->id)->count());
        $this->assertSame(0, DB::table('order_items')->where('tenant_id', $this->tenant->id)->count());

        // Res A: adoption rolled back atomically, remains active, owner_type is null
        $resA = InventoryReservation::where('reservation_key', $resKeyA)->first();
        $this->assertSame('active', $resA->status);
        $this->assertNull($resA->owner_type);

        // Res B: legitimately released by competing Inventory worker
        $resB = InventoryReservation::where('reservation_key', $resKeyB)->first();
        $this->assertSame('released', $resB->status);

        // Stock reserved: exactly 1.0000 (Res A active = 1.0000, Res B released = 0.0000)
        $this->assertSame(0, bccomp((string) $this->stockItem->fresh()->reserved, '1.0000', 4));
    }
}
