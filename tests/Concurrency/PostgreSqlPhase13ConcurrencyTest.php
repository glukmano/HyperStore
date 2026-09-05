<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Payables\Enums\PayableAvailabilityStatus;
use App\Core\Payables\Enums\PayableEntryType;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierLocation;
use Modules\Dropshipping\Models\SupplierProductVariant;
use Modules\Dropshipping\Models\TenantSupplierAccess;
use Modules\Fulfillment\Contracts\FulfillmentExecutionServiceInterface;
use Modules\Fulfillment\Enums\FulfillmentMode;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPayableEntry;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Contracts\ReturnRequestServiceInterface;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\ReturnItem;
use Modules\Order\Models\SellerOrder;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

/**
 * Genuine multi-process PostgreSQL race tests for Phase-13 (Orders, Fulfillment,
 * Dropshipping). Each worker is a fully independent PHP process with its own
 * database connection, synchronized via a file-touch barrier and racing against
 * PRODUCTION services under real Postgres row locks / constraint triggers.
 *
 * This intentionally mirrors the proc_open harness already established in
 * PostgreSqlMarketplaceConcurrencyTest — sequential in-process re-invocation of a
 * service is not concurrency, and does not exercise the lockForUpdate() /
 * CONSTRAINT TRIGGER serialization paths this suite is meant to prove.
 */
class PostgreSqlPhase13ConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private Vendor $vendor;

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
            $this->markTestSkipped('PostgreSqlPhase13ConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $uid = uniqid();

        $this->tenant = Tenant::create([
            'name' => 'P13 Conc Tenant',
            'slug' => 'p13-conc-'.$uid,
            'is_active' => true,
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                ],
            ],
        ]);

        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'P13 Store', 'slug' => 'p13-store-'.$uid, 'status' => 'active', 'url' => 'https://p13.example.com']);

        $this->market = Market::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'DE_'.strtoupper(Str::random(3)),
            'name' => 'Germany',
            'default_currency_code' => 'EUR',
            'default_locale_code' => 'de',
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        $this->channel = Channel::firstOrCreate(['handle' => 'web-p13-'.$uid], ['name' => 'Web', 'is_active' => true]);

        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'P13 Plan', 'code' => 'p13-plan-'.$uid]);

        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'P13 Vendor',
            'platform_slug' => 'p13-vendor-'.$uid,
            'legal_name' => 'P13 Vendor Corp',
            'email' => 'p13@vendor.com',
            'payout_currency' => 'EUR',
            'operational_status' => VendorOperationalStatus::Active,
        ]);
    }

    /**
     * Creates a fresh, placed, split-ready Order with one platform OrderItem and
     * one vendor OrderItem (quantity 2.0 each), matching the Phase-13 economics
     * example: subtotal 4000, discount 400, tax 684, commission 600.
     */
    private function makeOrder(): Order
    {
        $uid = uniqid();

        $cart = Cart::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'de',
            'status' => 'active',
        ]);

        $session = CheckoutSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'de',
            'state' => 'ready_for_order',
        ]);

        $order = Order::create([
            'order_number' => 'ORD-P13C-'.strtoupper($uid),
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'checkout_id' => $session->id,
            'currency' => 'EUR',
            'locale' => 'de',
            'order_status' => 'completed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'merchandise_subtotal_minor' => 10000,
            'discount_total_minor' => 1000,
            'tax_total_minor' => 1710,
            'shipping_total_minor' => 600,
            'grand_total_minor' => 11310,
            'commercial_model_snapshot' => 'platform_as_merchant_of_record',
            'shipping_snapshot' => [
                'original_amount' => 1000,
                'final_amount' => 600,
                'breakdown' => ['promotionDiscount' => 400],
            ],
            'customer_snapshot' => ['email' => 'p13c-'.$uid.'@example.com'],
            'version' => 1,
            'placed_at' => now(),
        ]);

        $platformProductId = DB::table('products')->insertGetId([
            'tenant_id' => $this->tenant->id, 'sku' => 'PROD-P13C-PLT-'.$uid,
            'product_type' => 'physical', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $vendorProductId = DB::table('products')->insertGetId([
            'tenant_id' => $this->tenant->id, 'sku' => 'PROD-P13C-VND-'.$uid,
            'product_type' => 'physical', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'product_id' => $platformProductId,
            'sku_snapshot' => 'SKU-P13C-PLT-'.$uid,
            'name_snapshot' => 'Platform Product',
            'product_type_snapshot' => 'physical',
            'requires_shipping_snapshot' => true,
            'quantity' => '2.00000000',
            'unit_price_minor' => 3000,
            'subtotal_minor' => 6000,
            'discount_minor' => 600,
            'tax_minor' => 1026,
            'total_minor' => 6426,
            'vendor_id' => null,
        ]);

        OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $vendorProductId,
            'order_id' => $order->id,
            'sku_snapshot' => 'SKU-P13C-VND-'.$uid,
            'name_snapshot' => 'Vendor Product',
            'product_type_snapshot' => 'physical',
            'requires_shipping_snapshot' => true,
            'quantity' => '2.00000000',
            'unit_price_minor' => 2000,
            'subtotal_minor' => 4000,
            'discount_minor' => 400,
            'tax_minor' => 684,
            'total_minor' => 4284,
            'vendor_id' => $this->vendor->id,
            'commission_amount_minor' => 600,
        ]);

        return $order->fresh(['items']);
    }

    /**
     * Spawns synchronized concurrent worker scripts via proc_open with a file
     * barrier, exactly mirroring PostgreSqlMarketplaceConcurrencyTest's harness.
     *
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/p13_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);

            $tmpFile = sys_get_temp_dir()."/worker_p13_{$idx}_".uniqid().'.php';
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

        usleep(50000); // 50ms buffer
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
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        @unlink($barrierFile);

        return $results;
    }

    private function getBootstrapScript(): string
    {
        $basePath = addslashes(base_path());

        return "<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$kernel = \$app->make(Illuminate\\Contracts\\Console\\Kernel::class);
\$kernel->bootstrap();

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.database' => 'hyperstore',
    'database.connections.pgsql.username' => 'lukman',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 5432,
]);
Illuminate\\Support\\Facades\\DB::purge('pgsql');
Illuminate\\Support\\Facades\\DB::setDefaultConnection('pgsql');
";
    }

    // ─────────────────────────────────────────────────────────────────
    // Race 1: MasterOrder split vs MasterOrder split
    // ─────────────────────────────────────────────────────────────────
    public function test_race_concurrent_master_order_split_is_serialized_and_idempotent(): void
    {
        $order = $this->makeOrder();
        $bootstrap = $this->getBootstrapScript();
        $orderId = $order->id;
        $tenantId = $this->tenant->id;

        $workerCode = function () use ($bootstrap, $orderId, $tenantId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$order = \\Modules\\Order\\Models\\Order::where('tenant_id', {$tenantId})->findOrFail({$orderId});
    \$service = app(\\Modules\\Order\\Contracts\\MasterOrderSplitServiceInterface::class);
    \$sellerOrders = \$service->splitOrder(\$order);
    \$ids = \$sellerOrders->pluck('id')->sort()->values()->implode(',');
    echo 'SUCCESS:' . \$ids;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";
        };

        $results = $this->executeConcurrently([$workerCode(), $workerCode()]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], 'Worker failed: '.$res['stdout'].$res['stderr']);
            $this->assertStringStartsWith('SUCCESS:', $res['stdout']);
        }

        $sellerOrders = SellerOrder::where('tenant_id', $tenantId)->where('order_id', $orderId)->get();
        $this->assertCount(2, $sellerOrders, 'Exactly one platform and one vendor SellerOrder must exist, never duplicated.');

        $expectedIds = $sellerOrders->pluck('id')->sort()->values()->implode(',');
        $this->assertSame('SUCCESS:'.$expectedIds, $results[0]['stdout']);
        $this->assertSame('SUCCESS:'.$expectedIds, $results[1]['stdout'], 'Both concurrent splitters must observe the identical winning SellerOrder set.');
    }

    // ─────────────────────────────────────────────────────────────────
    // Race 2: Supplier deactivate vs Supplier-backed Fulfillment creation
    // ─────────────────────────────────────────────────────────────────
    public function test_race_supplier_deactivation_vs_fulfillment_creation(): void
    {
        $order = $this->makeOrder();
        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);
        $platformSo = SellerOrder::where('order_id', $order->id)->where('seller_type', 'platform')->firstOrFail();
        $platformItem = $order->items->firstWhere('vendor_id', null);

        $supplier = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'scope_type' => 'tenant',
            'name' => 'Race2 Supplier', 'code' => 'R2_'.uniqid(), 'contact_email' => 'r2@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);
        $location = SupplierLocation::create([
            'uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'code' => 'R2-LOC', 'name' => 'R2 Loc',
            'country_code' => 'DE', 'city' => 'Munich', 'postal_code' => '80331', 'address_line1' => 'St 1', 'is_active' => true,
        ]);

        $bootstrap = $this->getBootstrapScript();
        $sellerOrderId = $platformSo->id;
        $itemId = $platformItem->id;
        $supplierId = $supplier->id;
        $locationId = $location->id;
        $tenantId = $this->tenant->id;

        $workerCreate = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$so = \\Modules\\Order\\Models\\SellerOrder::findOrFail({$sellerOrderId});
    \$service = app(\\Modules\\Fulfillment\\Contracts\\FulfillmentExecutionServiceInterface::class);
    \$fulfillments = \$service->createFulfillments(\$so, [
        [
            'mode' => 'dropshipping',
            'supplier_id' => {$supplierId},
            'supplier_location_id' => {$locationId},
            'items' => [['order_item_id' => {$itemId}, 'quantity' => '1.00000000']],
        ],
    ]);
    echo 'CREATE_SUCCESS:' . \$fulfillments->first()->id;
    exit(0);
} catch (\\DomainException \$e) {
    echo 'CREATE_REJECTED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $workerDeactivate = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Illuminate\\Support\\Facades\\DB::transaction(function () {
        \$s = \\Modules\\Dropshipping\\Models\\Supplier::lockForUpdate()->find({$supplierId});
        \$s->status = 'inactive';
        \$s->save();
    });
    echo 'DEACTIVATE_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$workerCreate, $workerDeactivate]);

        $this->assertSame(0, $results[0]['exit_code'], $results[0]['stdout'].$results[0]['stderr']);
        $this->assertSame(0, $results[1]['exit_code'], $results[1]['stdout'].$results[1]['stderr']);
        $this->assertSame('DEACTIVATE_SUCCESS', $results[1]['stdout']);

        $this->assertTrue(
            str_starts_with($results[0]['stdout'], 'CREATE_SUCCESS:') || str_starts_with($results[0]['stdout'], 'CREATE_REJECTED:'),
            'Fulfillment creation must either succeed (won the Supplier row lock first) or fail closed once deactivation committed: '.$results[0]['stdout']
        );

        if (str_starts_with($results[0]['stdout'], 'CREATE_REJECTED:')) {
            $this->assertStringContainsString('deactivated', $results[0]['stdout']);
        }

        // Final state: Supplier is inactive either way.
        $supplier->refresh();
        $this->assertFalse($supplier->is_active);
    }

    // ─────────────────────────────────────────────────────────────────
    // Race 3: TenantSupplierAccess disable vs Platform Supplier Fulfillment
    // ─────────────────────────────────────────────────────────────────
    public function test_race_tenant_supplier_access_disable_vs_platform_supplier_fulfillment(): void
    {
        $order = $this->makeOrder();
        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);
        $platformSo = SellerOrder::where('order_id', $order->id)->where('seller_type', 'platform')->firstOrFail();
        $platformItem = $order->items->firstWhere('vendor_id', null);

        $supplier = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => null, 'scope_type' => 'platform',
            'name' => 'Race3 Global Supplier', 'code' => 'R3_'.uniqid(), 'contact_email' => 'r3@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);
        $location = SupplierLocation::create([
            'uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'code' => 'R3-LOC', 'name' => 'R3 Loc',
            'country_code' => 'DE', 'city' => 'Berlin', 'postal_code' => '10115', 'address_line1' => 'St 1', 'is_active' => true,
        ]);
        $access = TenantSupplierAccess::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'is_enabled' => true]);

        $bootstrap = $this->getBootstrapScript();
        $sellerOrderId = $platformSo->id;
        $itemId = $platformItem->id;
        $supplierId = $supplier->id;
        $locationId = $location->id;
        $accessId = $access->id;

        $workerCreate = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$so = \\Modules\\Order\\Models\\SellerOrder::findOrFail({$sellerOrderId});
    \$service = app(\\Modules\\Fulfillment\\Contracts\\FulfillmentExecutionServiceInterface::class);
    \$fulfillments = \$service->createFulfillments(\$so, [
        [
            'mode' => 'dropshipping',
            'supplier_id' => {$supplierId},
            'supplier_location_id' => {$locationId},
            'items' => [['order_item_id' => {$itemId}, 'quantity' => '1.00000000']],
        ],
    ]);
    echo 'CREATE_SUCCESS:' . \$fulfillments->first()->id;
    exit(0);
} catch (\\DomainException \$e) {
    echo 'CREATE_REJECTED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $workerDisable = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Illuminate\\Support\\Facades\\DB::transaction(function () {
        \$a = \\Modules\\Dropshipping\\Models\\TenantSupplierAccess::lockForUpdate()->find({$accessId});
        \$a->is_enabled = false;
        \$a->save();
    });
    echo 'DISABLE_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$workerCreate, $workerDisable]);

        $this->assertSame(0, $results[0]['exit_code'], $results[0]['stdout'].$results[0]['stderr']);
        $this->assertSame(0, $results[1]['exit_code'], $results[1]['stdout'].$results[1]['stderr']);
        $this->assertSame('DISABLE_SUCCESS', $results[1]['stdout']);

        $this->assertTrue(
            str_starts_with($results[0]['stdout'], 'CREATE_SUCCESS:') || str_starts_with($results[0]['stdout'], 'CREATE_REJECTED:'),
            'Fulfillment creation must either succeed or fail closed once access was revoked: '.$results[0]['stdout']
        );

        if (str_starts_with($results[0]['stdout'], 'CREATE_REJECTED:')) {
            $this->assertStringContainsString('not enabled for tenant', $results[0]['stdout']);
        }

        $access->refresh();
        $this->assertFalse($access->is_enabled);
    }

    // ─────────────────────────────────────────────────────────────────
    // Race 4: Supplier deactivate vs PurchaseOrder creation
    // ─────────────────────────────────────────────────────────────────
    public function test_race_supplier_deactivation_vs_purchase_order_creation(): void
    {
        $order = $this->makeOrder();
        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);
        $platformSo = SellerOrder::where('order_id', $order->id)->where('seller_type', 'platform')->firstOrFail();
        $platformItem = $order->items->firstWhere('vendor_id', null);

        $supplier = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'scope_type' => 'tenant',
            'name' => 'Race4 Supplier', 'code' => 'R4_'.uniqid(), 'contact_email' => 'r4@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);
        $location = SupplierLocation::create([
            'uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'code' => 'R4-LOC', 'name' => 'R4 Loc',
            'country_code' => 'DE', 'city' => 'Munich', 'postal_code' => '80331', 'address_line1' => 'St 1', 'is_active' => true,
        ]);
        $spv = SupplierProductVariant::create([
            'tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'product_id' => $platformItem->product_id,
            'supplier_sku' => 'R4-SKU', 'canonical_wholesale_cost_minor' => 1500, 'currency' => 'EUR',
        ]);

        $fulfillments = app(FulfillmentExecutionServiceInterface::class)->createFulfillments($platformSo, [
            [
                'mode' => FulfillmentMode::DROPSHIPPING->value,
                'supplier_id' => $supplier->id,
                'supplier_location_id' => $location->id,
                'routing_snapshot' => [
                    'supplier_id' => $supplier->id,
                    'supplier_location_id' => $location->id,
                    'items' => [
                        [
                            'order_item_id' => $platformItem->id,
                            'supplier_product_variant_id' => $spv->id,
                            'supplier_sku' => 'R4-SKU',
                            'procurement_cost_minor' => 1500,
                            'procurement_currency' => 'EUR',
                        ],
                    ],
                ],
                'items' => [['order_item_id' => $platformItem->id, 'quantity' => '1.00000000']],
            ],
        ]);
        $fulfillment = $fulfillments->first();

        $bootstrap = $this->getBootstrapScript();
        $fulfillmentId = $fulfillment->id;
        $supplierId = $supplier->id;

        $workerCreatePo = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$f = \\Modules\\Fulfillment\\Models\\OrderFulfillment::findOrFail({$fulfillmentId});
    \$orchestrator = app(\\Modules\\Dropshipping\\Contracts\\DropshipOrderOrchestratorInterface::class);
    \$po = \$orchestrator->createPurchaseOrderForFulfillment(\$f);
    echo 'PO_SUCCESS:' . \$po->id;
    exit(0);
} catch (\\DomainException \$e) {
    echo 'PO_REJECTED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $workerDeactivate = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Illuminate\\Support\\Facades\\DB::transaction(function () {
        \$s = \\Modules\\Dropshipping\\Models\\Supplier::lockForUpdate()->find({$supplierId});
        \$s->status = 'inactive';
        \$s->save();
    });
    echo 'DEACTIVATE_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$workerCreatePo, $workerDeactivate]);

        $this->assertSame(0, $results[0]['exit_code'], $results[0]['stdout'].$results[0]['stderr']);
        $this->assertSame(0, $results[1]['exit_code'], $results[1]['stdout'].$results[1]['stderr']);
        $this->assertSame('DEACTIVATE_SUCCESS', $results[1]['stdout']);

        $this->assertTrue(
            str_starts_with($results[0]['stdout'], 'PO_SUCCESS:') || str_starts_with($results[0]['stdout'], 'PO_REJECTED:'),
            'PurchaseOrder creation must either succeed or fail closed once deactivation committed: '.$results[0]['stdout']
        );

        if (str_starts_with($results[0]['stdout'], 'PO_REJECTED:')) {
            $this->assertStringContainsString('deactivated', $results[0]['stdout']);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Race 5: duplicate dropship PurchaseOrder creation
    // ─────────────────────────────────────────────────────────────────
    public function test_race_duplicate_dropship_purchase_order_creation_is_idempotent(): void
    {
        $order = $this->makeOrder();
        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);
        $platformSo = SellerOrder::where('order_id', $order->id)->where('seller_type', 'platform')->firstOrFail();
        $platformItem = $order->items->firstWhere('vendor_id', null);

        $supplier = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'scope_type' => 'tenant',
            'name' => 'Race5 Supplier', 'code' => 'R5_'.uniqid(), 'contact_email' => 'r5@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);
        $location = SupplierLocation::create([
            'uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'code' => 'R5-LOC', 'name' => 'R5 Loc',
            'country_code' => 'DE', 'city' => 'Munich', 'postal_code' => '80331', 'address_line1' => 'St 1', 'is_active' => true,
        ]);
        $spv = SupplierProductVariant::create([
            'tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'product_id' => $platformItem->product_id,
            'supplier_sku' => 'R5-SKU', 'canonical_wholesale_cost_minor' => 1500, 'currency' => 'EUR',
        ]);

        $fulfillments = app(FulfillmentExecutionServiceInterface::class)->createFulfillments($platformSo, [
            [
                'mode' => FulfillmentMode::DROPSHIPPING->value,
                'supplier_id' => $supplier->id,
                'supplier_location_id' => $location->id,
                'routing_snapshot' => [
                    'supplier_id' => $supplier->id,
                    'supplier_location_id' => $location->id,
                    'items' => [
                        [
                            'order_item_id' => $platformItem->id,
                            'supplier_product_variant_id' => $spv->id,
                            'supplier_sku' => 'R5-SKU',
                            'procurement_cost_minor' => 1500,
                            'procurement_currency' => 'EUR',
                        ],
                    ],
                ],
                'items' => [['order_item_id' => $platformItem->id, 'quantity' => '1.00000000']],
            ],
        ]);
        $fulfillment = $fulfillments->first();

        $bootstrap = $this->getBootstrapScript();
        $fulfillmentId = $fulfillment->id;

        $workerCode = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$f = \\Modules\\Fulfillment\\Models\\OrderFulfillment::findOrFail({$fulfillmentId});
    \$orchestrator = app(\\Modules\\Dropshipping\\Contracts\\DropshipOrderOrchestratorInterface::class);
    \$po = \$orchestrator->createPurchaseOrderForFulfillment(\$f);
    echo 'SUCCESS:' . \$po->id;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$workerCode, $workerCode]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], 'Worker failed: '.$res['stdout'].$res['stderr']);
            $this->assertStringStartsWith('SUCCESS:', $res['stdout']);
        }

        $poCount = DB::table('purchase_orders')->where('order_fulfillment_id', $fulfillmentId)->count();
        $this->assertSame(1, $poCount, 'Exactly one PurchaseOrder must be materialized for a dropship fulfillment, even under a genuine concurrent double-submit.');

        $this->assertSame($results[0]['stdout'], $results[1]['stdout'], 'Both concurrent creators must observe the identical winning PurchaseOrder id.');
    }

    // ─────────────────────────────────────────────────────────────────
    // Race 6: concurrent return approval quantity race
    // ─────────────────────────────────────────────────────────────────
    public function test_race_concurrent_return_approval_cannot_exceed_order_item_quantity(): void
    {
        $order = $this->makeOrder();
        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);
        $vendorItem = $order->items->firstWhere('vendor_id', $this->vendor->id);

        $returnRequest = app(ReturnRequestServiceInterface::class)->createReturnRequest(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            customerId: null,
            items: [
                ['order_item_id' => $vendorItem->id, 'quantity' => '2.00000000', 'reason' => 'Wrong size', 'condition' => 'unopened'],
            ]
        );
        $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenant->id;
        $sellerReturnId = $vendorSr->id;
        $itemId = $vendorItem->id;

        $workerCode = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Order\\Contracts\\ReturnRequestServiceInterface::class);
    \$sr = \$service->approveReturnItem(
        tenantId: {$tenantId},
        sellerReturnId: {$sellerReturnId},
        orderItemId: {$itemId},
        quantityToApprove: '1.50000000'
    );
    echo 'SUCCESS:' . (string) \$sr->refresh()->id;
    exit(0);
} catch (\\InvalidArgumentException \$e) {
    echo 'REJECTED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$workerCode, $workerCode]);

        $this->assertSame(0, $results[0]['exit_code'], $results[0]['stdout'].$results[0]['stderr']);
        $this->assertSame(0, $results[1]['exit_code'], $results[1]['stdout'].$results[1]['stderr']);

        $successCount = 0;
        $rejectedCount = 0;
        foreach ($results as $res) {
            if (str_starts_with($res['stdout'], 'SUCCESS:')) {
                $successCount++;
            } elseif (str_starts_with($res['stdout'], 'REJECTED:')) {
                $this->assertStringContainsString('exceeds OrderItem quantity', $res['stdout']);
                $rejectedCount++;
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one of the two concurrent 1.5-unit approvals may succeed (2.0 total on-hand).');
        $this->assertSame(1, $rejectedCount, 'The other concurrent approval must fail closed (1.5 + 1.5 > 2.0).');

        $totalApproved = (string) ReturnItem::where('seller_return_id', $sellerReturnId)->where('order_item_id', $itemId)->value('quantity_approved');
        $this->assertSame('1.50000000', $totalApproved, 'Total approved quantity must never exceed the OrderItem quantity even under a genuine race.');
    }

    // ─────────────────────────────────────────────────────────────────
    // Race 7: concurrent refund finalization
    // ─────────────────────────────────────────────────────────────────
    public function test_race_concurrent_refund_finalization_with_durable_operation_uuid(): void
    {
        $order = $this->makeOrder();

        app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 11310,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));

        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);
        $vendorItem = $order->items->firstWhere('vendor_id', $this->vendor->id);

        $returnRequest = app(ReturnRequestServiceInterface::class)->createReturnRequest(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            customerId: null,
            items: [
                ['order_item_id' => $vendorItem->id, 'quantity' => '1.00000000', 'reason' => 'Defective', 'condition' => 'unopened'],
            ]
        );
        $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');

        app(ReturnRequestServiceInterface::class)->approveReturnItem(
            tenantId: $this->tenant->id,
            sellerReturnId: $vendorSr->id,
            orderItemId: $vendorItem->id,
            quantityToApprove: '1.00000000'
        );

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenant->id;
        $sellerReturnId = $vendorSr->id;

        $workerCode = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$orchestrator = app(\\Modules\\Order\\Contracts\\ReturnRefundOrchestratorInterface::class);
    \$sr = \$orchestrator->finalizeRefund({$tenantId}, {$sellerReturnId});
    echo 'SUCCESS:' . \$sr->payment_refund_transaction_id;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$workerCode, $workerCode]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], 'Worker failed: '.$res['stdout'].$res['stderr']);
            $this->assertStringStartsWith('SUCCESS:', $res['stdout']);
        }

        $this->assertSame($results[0]['stdout'], $results[1]['stdout'], 'Both concurrent finalizers must observe the identical winning refund PaymentTransaction id.');

        $entryCount = VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $this->vendor->id)
            ->where('entry_type', 'refund_adjustment')
            ->count();
        $this->assertSame(1, $entryCount, 'Exactly one subledger refund_adjustment entry must exist despite a genuine concurrent double-finalize.');

        $txCount = DB::table('payment_transactions')
            ->where('tenant_id', $tenantId)
            ->where('operation_type', 'refund')
            ->count();
        $this->assertSame(1, $txCount, 'Exactly one refund PaymentTransaction must exist despite a genuine concurrent double-finalize.');
    }

    // ─────────────────────────────────────────────────────────────────
    // Race 8: cancellation vs Fulfillment dispatch
    // ─────────────────────────────────────────────────────────────────
    public function test_race_cancellation_vs_fulfillment_dispatch(): void
    {
        $order = $this->makeOrder();
        // Cancellation is only a valid transition from a non-terminal order status.
        $order->update(['order_status' => 'processing']);
        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);
        $platformSo = SellerOrder::where('order_id', $order->id)->where('seller_type', 'platform')->firstOrFail();
        $platformItem = $order->items->firstWhere('vendor_id', null);

        $fulfillments = app(FulfillmentExecutionServiceInterface::class)->createFulfillments($platformSo, [
            [
                'mode' => FulfillmentMode::OWN_STOCK->value,
                'items' => [['order_item_id' => $platformItem->id, 'quantity' => '1.00000000']],
            ],
        ]);
        $fulfillment = $fulfillments->first();

        $bootstrap = $this->getBootstrapScript();
        $orderId = $order->id;
        $fulfillmentId = $fulfillment->id;

        $workerCancel = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$order = \\Modules\\Order\\Models\\Order::findOrFail({$orderId});
    \$service = app(\\Modules\\Order\\Contracts\\OrderCancellationServiceInterface::class);
    \$service->cancel(\$order, 'Customer requested cancellation');
    echo 'CANCEL_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $workerDispatch = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$f = \\Modules\\Fulfillment\\Models\\OrderFulfillment::findOrFail({$fulfillmentId});
    \$service = app(\\Modules\\Fulfillment\\Contracts\\FulfillmentExecutionServiceInterface::class);
    \$shipment = \$service->shipFulfillment(\$f, 'DHL', 'TRACK-RACE8-' . uniqid());
    echo 'DISPATCH_SUCCESS:' . \$shipment->id;
    exit(0);
} catch (\\DomainException \$e) {
    echo 'DISPATCH_REJECTED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$workerCancel, $workerDispatch]);

        $this->assertSame(0, $results[0]['exit_code'], $results[0]['stdout'].$results[0]['stderr']);
        $this->assertSame(0, $results[1]['exit_code'], $results[1]['stdout'].$results[1]['stderr']);
        $this->assertSame('CANCEL_SUCCESS', $results[0]['stdout']);

        $fulfillment->refresh();
        $order->refresh();

        $this->assertSame('cancelled', $order->order_status);

        if (str_starts_with($results[1]['stdout'], 'DISPATCH_SUCCESS:')) {
            $this->assertSame('shipped', $fulfillment->status, 'If dispatch won the race, the fulfillment must be marked shipped.');
        } else {
            $this->assertStringStartsWith('DISPATCH_REJECTED:', $results[1]['stdout']);
            $this->assertStringContainsString('has been cancelled', $results[1]['stdout']);
            $this->assertNotSame('shipped', $fulfillment->status, 'If cancellation won the race, dispatch must fail closed and never mark the fulfillment shipped.');
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Race 9: Marketplace refund_adjustment vs Phase-11 payout allocation
    // ─────────────────────────────────────────────────────────────────
    public function test_race_refund_adjustment_vs_payout_allocation(): void
    {
        VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'race9_seed',
            'source_uuid' => 'race9-'.uniqid(),
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => PayableAvailabilityStatus::Available,
        ]);

        $order = $this->makeOrder();

        app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 11310,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));

        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);
        $vendorItem = $order->items->firstWhere('vendor_id', $this->vendor->id);

        $returnRequest = app(ReturnRequestServiceInterface::class)->createReturnRequest(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            customerId: null,
            items: [
                ['order_item_id' => $vendorItem->id, 'quantity' => '2.00000000', 'reason' => 'Full return', 'condition' => 'unopened'],
            ]
        );
        $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');

        app(ReturnRequestServiceInterface::class)->approveReturnItem(
            tenantId: $this->tenant->id,
            sellerReturnId: $vendorSr->id,
            orderItemId: $vendorItem->id,
            quantityToApprove: '2.00000000'
        );
        // Expected vendor_payable_debit for a full return of the vendor item: 3000
        // (subtotal 4000 - discount 400 = 3600 gross reversal; 3600 - 600 commission = 3000).
        $this->assertSame(3000, $vendorSr->refresh()->vendor_payable_debit_minor);

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenant->id;
        $vendorId = $this->vendor->id;
        $sellerReturnId = $vendorSr->id;

        $workerRefund = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$orchestrator = app(\\Modules\\Order\\Contracts\\ReturnRefundOrchestratorInterface::class);
    \$sr = \$orchestrator->finalizeRefund({$tenantId}, {$sellerReturnId});
    echo 'REFUND_SUCCESS:' . \$sr->payment_refund_transaction_id;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $workerPayout = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Marketplace\\Contracts\\PayoutServiceInterface::class);
    \$req = \$service->requestPayout({$tenantId}, {$vendorId}, 8000, 'EUR');
    echo 'PAYOUT_SUCCESS:' . \$req->id;
    exit(0);
} catch (\\Modules\\Marketplace\\Exceptions\\InsufficientPayableBalanceException \$e) {
    echo 'PAYOUT_REJECTED_INSUFFICIENT:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$workerRefund, $workerPayout]);

        $this->assertSame(0, $results[0]['exit_code'], $results[0]['stdout'].$results[0]['stderr']);
        $this->assertSame(0, $results[1]['exit_code'], $results[1]['stdout'].$results[1]['stderr']);
        $this->assertStringStartsWith('REFUND_SUCCESS:', $results[0]['stdout']);

        // Exactly one refund_adjustment entry, correctly sized, regardless of interleaving order.
        $refundEntries = VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('entry_type', 'refund_adjustment')
            ->get();
        $this->assertCount(1, $refundEntries);
        $this->assertSame(3000, $refundEntries->first()->net_amount_minor);

        $this->assertTrue(
            str_starts_with($results[1]['stdout'], 'PAYOUT_SUCCESS:') || str_starts_with($results[1]['stdout'], 'PAYOUT_REJECTED_INSUFFICIENT:'),
            'Payout request must either succeed against the pre-refund balance or fail closed once the refund debit committed first: '.$results[1]['stdout']
        );

        $subledger = app(VendorPayableSubledgerServiceInterface::class);
        $bal = $subledger->getBalances($tenantId, $vendorId, 'EUR');
        $this->assertGreaterThanOrEqual(0, $bal->withdrawableBalanceMinor, 'Withdrawable balance must never go negative regardless of race interleaving.');
    }
}
