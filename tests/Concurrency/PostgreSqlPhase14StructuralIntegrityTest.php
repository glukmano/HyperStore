<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\Warehouse;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Contracts\ReturnRequestServiceInterface;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\ReturnItem;
use Tests\TestCase;

/**
 * Proves PostgreSQL itself (the Phase-14 additive composite FKs, ADR-0126) rejects
 * structurally invalid rows even when they bypass all application-layer checks
 * (raw DB::table() inserts). Mirrors PostgreSqlPhase13StructuralIntegrityTest's pattern.
 */
class PostgreSqlPhase14StructuralIntegrityTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

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
            $this->markTestSkipped('PostgreSqlPhase14StructuralIntegrityTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $uid = uniqid();
        $this->tenantA = Tenant::create(['name' => 'P14 Struct Tenant A', 'slug' => 'p14-si-a-'.$uid, 'is_active' => true]);
        $this->tenantB = Tenant::create(['name' => 'P14 Struct Tenant B', 'slug' => 'p14-si-b-'.$uid, 'is_active' => true]);
    }

    private function assertRejectedByDatabase(callable $insert, string $expectedMessageFragment): void
    {
        try {
            DB::transaction($insert);
            $this->fail("Expected PostgreSQL to reject the insert with [{$expectedMessageFragment}], but it succeeded.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($expectedMessageFragment, $e->getMessage());
        }
    }

    public function test_postgresql_rejects_warehouse_vendor_from_a_different_tenant(): void
    {
        $plan = VendorPlan::create(['tenant_id' => $this->tenantA->id, 'name' => 'P', 'code' => 'p14-si-plan-'.uniqid()]);
        $vendorA = Vendor::create([
            'tenant_id' => $this->tenantA->id, 'vendor_plan_id' => $plan->id, 'name' => 'V A',
            'platform_slug' => 'va-'.uniqid(), 'legal_name' => 'V A Corp', 'email' => 'va@example.com', 'payout_currency' => 'EUR',
        ]);

        // Warehouse claims Tenant B, but vendor_id belongs to Tenant A.
        $this->assertRejectedByDatabase(function () use ($vendorA): void {
            DB::table('warehouses')->insert([
                'tenant_id' => $this->tenantB->id,
                'code' => 'WH-XTEN-'.uniqid(),
                'name' => 'Cross Tenant WH',
                'type' => 'fulfillment_center',
                'ownership_type' => 'vendor',
                'vendor_id' => $vendorA->id,
                'status' => 'active',
                'country_code' => 'CH',
                'priority' => 0,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'fk_warehouses_vendor');
    }

    public function test_postgresql_rejects_inventory_transfer_source_from_a_different_tenant(): void
    {
        $whB = Warehouse::create(['tenant_id' => $this->tenantB->id, 'code' => 'WHB-'.uniqid(), 'name' => 'WH B', 'country_code' => 'CH']);
        $srcA = InventorySource::create(['tenant_id' => $this->tenantA->id, 'code' => 'SRCA-'.uniqid(), 'name' => 'Src A']);
        $srcB = InventorySource::create(['tenant_id' => $this->tenantB->id, 'warehouse_id' => $whB->id, 'code' => 'SRCB-'.uniqid(), 'name' => 'Src B']);

        // Transfer claims Tenant B, but source_inventory_source_id belongs to Tenant A.
        $this->assertRejectedByDatabase(function () use ($srcA, $srcB): void {
            DB::table('inventory_transfers')->insert([
                'tenant_id' => $this->tenantB->id,
                'transfer_number' => 'TR-XTEN-'.uniqid(),
                'source_inventory_source_id' => $srcA->id,
                'destination_inventory_source_id' => $srcB->id,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'fk_it_tenant_source');
    }

    public function test_postgresql_rejects_inventory_transfer_item_from_a_different_tenant_than_its_transfer(): void
    {
        $srcA1 = InventorySource::create(['tenant_id' => $this->tenantA->id, 'code' => 'SRCA1-'.uniqid(), 'name' => 'Src A1']);
        $srcA2 = InventorySource::create(['tenant_id' => $this->tenantA->id, 'code' => 'SRCA2-'.uniqid(), 'name' => 'Src A2']);

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenantA->id, productType: 'physical', sku: 'P14-SI-'.uniqid(),
            translations: ['en' => ['name' => 'SI Product']],
        ));

        $transfer = InventoryTransfer::create([
            'tenant_id' => $this->tenantA->id,
            'transfer_number' => 'TR-SI-'.uniqid(),
            'source_inventory_source_id' => $srcA1->id,
            'destination_inventory_source_id' => $srcA2->id,
            'status' => 'draft',
        ]);

        // Item claims Tenant B, but inventory_transfer_id belongs to a Tenant A transfer.
        $this->assertRejectedByDatabase(function () use ($transfer, $product): void {
            DB::table('inventory_transfer_items')->insert([
                'tenant_id' => $this->tenantB->id,
                'inventory_transfer_id' => $transfer->id,
                'product_id' => $product->id,
                'requested_quantity' => '1.0000',
                'dispatched_quantity' => 0,
                'received_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'fk_iti_tenant_transfer');
    }

    public function test_postgresql_rejects_return_item_destination_source_from_a_different_tenant(): void
    {
        $srcB = InventorySource::create(['tenant_id' => $this->tenantB->id, 'code' => 'SRCB-RI-'.uniqid(), 'name' => 'Src B RI']);

        // Uses Phase-13's own composite-FK-satisfying chain via raw inserts kept minimal:
        // this test targets ONLY the new fk_ri_tenant_dest_source constraint, so we build
        // just enough of the return_requests/seller_returns/order_items chain for Tenant A.
        $store = Store::create(['tenant_id' => $this->tenantA->id, 'name' => 'SI Store', 'slug' => 'p14-si-store-'.uniqid(), 'status' => 'active', 'url' => 'https://si.example.com']);
        $market = Market::create(['tenant_id' => $this->tenantA->id, 'code' => 'DE_'.strtoupper(Str::random(3)), 'name' => 'Germany', 'default_currency_code' => 'EUR', 'default_locale_code' => 'de', 'timezone' => 'Europe/Berlin', 'is_active' => true]);
        $channel = Channel::create(['name' => 'C', 'type' => 'website', 'handle' => 'p14-si-'.uniqid(), 'is_active' => true]);
        $cart = Cart::create(['tenant_id' => $this->tenantA->id, 'store_id' => $store->id, 'market_id' => $market->id, 'channel_id' => $channel->id, 'currency' => 'EUR', 'locale' => 'de', 'status' => 'active']);
        $session = CheckoutSession::create(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'cart_id' => $cart->id, 'store_id' => $store->id, 'market_id' => $market->id, 'channel_id' => $channel->id, 'currency' => 'EUR', 'locale' => 'de', 'state' => 'ready_for_order']);

        $order = Order::create([
            'order_number' => 'ORD-SI-'.uniqid(), 'tenant_id' => $this->tenantA->id, 'store_id' => $store->id,
            'market_id' => $market->id, 'channel_id' => $channel->id, 'checkout_id' => $session->id,
            'currency' => 'EUR', 'locale' => 'de', 'order_status' => 'completed', 'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled', 'merchandise_subtotal_minor' => 1000, 'discount_total_minor' => 0,
            'tax_total_minor' => 0, 'shipping_total_minor' => 0, 'grand_total_minor' => 1000,
            'commercial_model_snapshot' => 'platform_as_merchant_of_record', 'customer_snapshot' => ['email' => 'si@example.com'],
            'version' => 1, 'placed_at' => now(),
        ]);
        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id, 'order_id' => $order->id, 'sku_snapshot' => 'SI-SKU',
            'name_snapshot' => 'SI Product', 'product_type_snapshot' => 'physical', 'requires_shipping_snapshot' => false,
            'quantity' => '1.00000000', 'unit_price_minor' => 1000, 'subtotal_minor' => 1000, 'discount_minor' => 0,
            'tax_minor' => 0, 'total_minor' => 1000, 'vendor_id' => null,
        ]);
        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);

        $returnRequest = app(ReturnRequestServiceInterface::class)->createReturnRequest(
            tenantId: $this->tenantA->id, orderId: $order->id, customerId: null,
            items: [['order_item_id' => $orderItem->id, 'quantity' => '1.00000000', 'reason' => 'x', 'condition' => 'unopened']],
        );
        $sellerReturn = $returnRequest->sellerReturns->first();
        $returnItem = ReturnItem::where('seller_return_id', $sellerReturn->id)->first();

        // return_items row belongs to Tenant A, but destination_inventory_source_id belongs to Tenant B.
        $this->assertRejectedByDatabase(function () use ($returnItem, $srcB): void {
            DB::table('return_items')->where('id', $returnItem->id)->update([
                'destination_inventory_source_id' => $srcB->id,
                'disposition_operation_uuid' => (string) Str::uuid(),
            ]);
        }, 'fk_ri_tenant_dest_source');
    }
}
