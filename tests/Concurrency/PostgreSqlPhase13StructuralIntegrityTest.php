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
use Modules\Checkout\Models\CheckoutSession;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierLocation;
use Modules\Dropshipping\Models\SupplierProductVariant;
use Modules\Dropshipping\Models\TenantSupplierAccess;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\SellerOrder;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * Proves that PostgreSQL itself (composite foreign keys + constraint triggers
 * created in the phase-13 migration) rejects structurally invalid rows even
 * when they bypass all application-layer service checks (raw DB::table()
 * inserts). Application checks alone are not sufficient evidence of these
 * invariants; this suite exercises the database engine directly.
 */
class PostgreSqlPhase13StructuralIntegrityTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Market $marketA;

    private Channel $channel;

    private Vendor $vendorA;

    private Vendor $vendorB;

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
            $this->markTestSkipped('PostgreSqlPhase13StructuralIntegrityTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $uid = uniqid();

        $this->tenantA = Tenant::create(['name' => 'Struct Tenant A', 'slug' => 'si-a-'.$uid, 'is_active' => true]);
        $this->tenantB = Tenant::create(['name' => 'Struct Tenant B', 'slug' => 'si-b-'.$uid, 'is_active' => true]);

        $this->storeA = Store::create(['tenant_id' => $this->tenantA->id, 'name' => 'SI Store A', 'slug' => 'si-store-a-'.$uid, 'status' => 'active', 'url' => 'https://si-a.example.com']);

        $this->marketA = Market::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'DE_'.strtoupper(Str::random(3)),
            'name' => 'Germany',
            'default_currency_code' => 'EUR',
            'default_locale_code' => 'de',
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        $this->channel = Channel::firstOrCreate(['handle' => 'web-si-'.$uid], ['name' => 'Web', 'is_active' => true]);

        $plan = VendorPlan::create(['tenant_id' => $this->tenantA->id, 'name' => 'SI Plan', 'code' => 'si-plan-'.$uid]);

        $this->vendorA = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'SI Vendor A',
            'platform_slug' => 'si-vendor-a-'.$uid,
            'legal_name' => 'SI Vendor A Corp',
            'email' => 'si-a@vendor.com',
            'payout_currency' => 'EUR',
        ]);

        $this->vendorB = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'SI Vendor B',
            'platform_slug' => 'si-vendor-b-'.$uid,
            'legal_name' => 'SI Vendor B Corp',
            'email' => 'si-b@vendor.com',
            'payout_currency' => 'EUR',
        ]);
    }

    /**
     * Creates a minimal, valid, placed Order (+ one OrderItem) for the given tenant.
     */
    private function makeOrder(Tenant $tenant, Store $store, Market $market): Order
    {
        $uid = uniqid();

        $cart = Cart::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'de',
            'status' => 'active',
        ]);

        $session = CheckoutSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'de',
            'state' => 'ready_for_order',
        ]);

        $order = Order::create([
            'order_number' => 'ORD-SI-'.strtoupper($uid),
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $this->channel->id,
            'checkout_id' => $session->id,
            'currency' => 'EUR',
            'locale' => 'de',
            'order_status' => 'completed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'merchandise_subtotal_minor' => 5000,
            'discount_total_minor' => 0,
            'tax_total_minor' => 0,
            'shipping_total_minor' => 0,
            'grand_total_minor' => 5000,
            'commercial_model_snapshot' => 'platform_as_merchant_of_record',
            'shipping_snapshot' => ['original_amount' => 0, 'final_amount' => 0, 'breakdown' => ['promotionDiscount' => 0]],
            'customer_snapshot' => ['email' => 'si-'.$uid.'@example.com'],
            'version' => 1,
            'placed_at' => now(),
        ]);

        OrderItem::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'sku_snapshot' => 'SKU-SI-'.$uid,
            'name_snapshot' => 'SI Product',
            'product_type_snapshot' => 'physical',
            'requires_shipping_snapshot' => true,
            'quantity' => '1.00000000',
            'unit_price_minor' => 5000,
            'subtotal_minor' => 5000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 5000,
            'vendor_id' => null,
        ]);

        return $order->fresh(['items']);
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

    public function test_postgresql_rejects_cross_tenant_seller_order_relationship(): void
    {
        $orderA = $this->makeOrder($this->tenantA, $this->storeA, $this->marketA);

        // Attempt to raw-insert a SellerOrder claiming tenant B while pointing at Tenant A's Order.
        $this->assertRejectedByDatabase(function () use ($orderA): void {
            DB::table('seller_orders')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeA->id,
                'order_id' => $orderA->id,
                'seller_order_number' => 'SO-XTEN-'.uniqid(),
                'seller_type' => 'platform',
                'vendor_id' => null,
                'commercial_model' => 'platform_as_merchant_of_record',
                'currency' => 'EUR',
                'subtotal_minor' => 5000,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 5000,
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'fk_so_tenant_order');
    }

    public function test_postgresql_rejects_cross_tenant_seller_order_item_relationship(): void
    {
        $orderA = $this->makeOrder($this->tenantA, $this->storeA, $this->marketA);
        $sellerOrders = app(MasterOrderSplitServiceInterface::class)->splitOrder($orderA);
        $sellerOrder = $sellerOrders->first();

        $orderB = $this->makeOrder($this->tenantB, Store::create([
            'tenant_id' => $this->tenantB->id, 'name' => 'SI Store B', 'slug' => 'si-store-b-'.uniqid(), 'status' => 'active', 'url' => 'https://si-b.example.com',
        ]), Market::create([
            'tenant_id' => $this->tenantB->id, 'code' => 'FR_'.strtoupper(Str::random(3)), 'name' => 'France',
            'default_currency_code' => 'EUR', 'default_locale_code' => 'fr', 'timezone' => 'Europe/Paris', 'is_active' => true,
        ]));
        $foreignItem = $orderB->items->first();

        // seller_order_id belongs to Tenant A, but order_item_id belongs to Tenant B.
        $this->assertRejectedByDatabase(function () use ($sellerOrder, $foreignItem): void {
            DB::table('seller_order_items')->insert([
                'tenant_id' => $this->tenantA->id,
                'seller_order_id' => $sellerOrder->id,
                'order_item_id' => $foreignItem->id,
                'quantity' => '1.00000000',
                'subtotal_minor' => 5000,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 5000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'fk_soi_tenant_order_item');
    }

    public function test_postgresql_rejects_supplier_offer_with_mismatched_supplier_location(): void
    {
        $supplierX = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'scope_type' => 'tenant',
            'name' => 'Supplier X', 'code' => 'SX_'.uniqid(), 'contact_email' => 'sx@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);
        $supplierY = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'scope_type' => 'tenant',
            'name' => 'Supplier Y', 'code' => 'SY_'.uniqid(), 'contact_email' => 'sy@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);

        $locationY = SupplierLocation::create([
            'uuid' => (string) Str::uuid(), 'supplier_id' => $supplierY->id, 'code' => 'LOC-Y', 'name' => 'Loc Y',
            'country_code' => 'DE', 'city' => 'Berlin', 'postal_code' => '10115', 'address_line1' => 'St 1', 'is_active' => true,
        ]);

        $productId = DB::table('products')->insertGetId([
            'tenant_id' => $this->tenantA->id, 'sku' => 'SI-PROD-'.uniqid(),
            'product_type' => 'physical', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $spv = SupplierProductVariant::create([
            'tenant_id' => $this->tenantA->id,
            'supplier_id' => $supplierX->id,
            'product_id' => $productId,
            'supplier_sku' => 'SX-SKU-'.uniqid(),
            'canonical_wholesale_cost_minor' => 1000,
            'currency' => 'EUR',
        ]);

        // supplier_id = Supplier X, but supplier_location_id belongs to Supplier Y.
        $this->assertRejectedByDatabase(function () use ($supplierX, $spv, $locationY): void {
            DB::table('supplier_offers')->insert([
                'supplier_id' => $supplierX->id,
                'supplier_product_variant_id' => $spv->id,
                'supplier_location_id' => $locationY->id,
                'stock_quantity' => 10,
                'is_available' => true,
                'lead_time_days' => 1,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'fk_so_sl');
    }

    public function test_postgresql_rejects_purchase_order_for_tenant_b_pointing_at_tenant_a_scoped_supplier(): void
    {
        $tenantSupplier = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'scope_type' => 'tenant',
            'name' => 'A Only Supplier', 'code' => 'AONLY_'.uniqid(), 'contact_email' => 'a-only@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);

        $this->assertRejectedByDatabase(function () use ($tenantSupplier): void {
            DB::table('purchase_orders')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenantB->id,
                'supplier_id' => $tenantSupplier->id,
                'order_fulfillment_id' => null,
                'po_number' => 'PO-XTEN-'.uniqid(),
                'type' => 'dropship',
                'status' => 'draft',
                'currency' => 'EUR',
                'subtotal_minor' => 1000,
                'total_minor' => 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'does not match Tenant Supplier tenant');
    }

    public function test_postgresql_rejects_purchase_order_from_unauthorized_platform_supplier(): void
    {
        $platformSupplier = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => null, 'scope_type' => 'platform',
            'name' => 'Global Unauth Supplier', 'code' => 'GU_'.uniqid(), 'contact_email' => 'gu@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);
        // Deliberately NOT creating a TenantSupplierAccess row for tenantA.

        $this->assertRejectedByDatabase(function () use ($platformSupplier): void {
            DB::table('purchase_orders')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenantA->id,
                'supplier_id' => $platformSupplier->id,
                'order_fulfillment_id' => null,
                'po_number' => 'PO-UNAUTH-'.uniqid(),
                'type' => 'dropship',
                'status' => 'draft',
                'currency' => 'EUR',
                'subtotal_minor' => 1000,
                'total_minor' => 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'is not authorized to procure from Platform Supplier');
    }

    public function test_postgresql_rejects_vendor_a_seller_order_procuring_from_vendor_b_private_supplier(): void
    {
        $privateSupplierB = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'scope_type' => 'private_vendor',
            'vendor_id' => $this->vendorB->id, 'name' => 'Vendor B Private Supplier', 'code' => 'PVB_'.uniqid(),
            'contact_email' => 'pvb@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);

        $orderA = $this->makeOrder($this->tenantA, $this->storeA, $this->marketA);
        // Re-point the order item to Vendor A so the split creates a Vendor A SellerOrder.
        $orderA->items->first()->update(['vendor_id' => $this->vendorA->id]);
        $orderA->refresh();

        $sellerOrders = app(MasterOrderSplitServiceInterface::class)->splitOrder($orderA);
        $vendorASellerOrder = $sellerOrders->firstWhere('vendor_id', $this->vendorA->id);
        $this->assertNotNull($vendorASellerOrder, 'Vendor A SellerOrder must exist for this scenario.');

        // A fulfillment with NO supplier (own_stock) purely to obtain a valid order_fulfillment_id
        // that references the Vendor A SellerOrder, without tripping the fulfillment-level trigger.
        $fulfillmentId = DB::table('order_fulfillments')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'seller_order_id' => $vendorASellerOrder->id,
            'parent_fulfillment_id' => null,
            'fulfillment_number' => 'FUL-XVEND-'.uniqid(),
            'fulfillment_mode' => 'own_stock',
            'status' => 'pending',
            'supplier_id' => null,
            'supplier_location_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to procure Vendor A's SellerOrder from Vendor B's private Supplier.
        $this->assertRejectedByDatabase(function () use ($privateSupplierB, $fulfillmentId): void {
            DB::table('purchase_orders')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenantA->id,
                'supplier_id' => $privateSupplierB->id,
                'order_fulfillment_id' => $fulfillmentId,
                'po_number' => 'PO-XVEND-'.uniqid(),
                'type' => 'dropship',
                'status' => 'draft',
                'currency' => 'EUR',
                'subtotal_minor' => 1000,
                'total_minor' => 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'Vendor isolation violation');
    }

    public function test_postgresql_rejects_cross_tenant_refund_payment_transaction_reference(): void
    {
        $orderA = $this->makeOrder($this->tenantA, $this->storeA, $this->marketA);
        $sellerOrders = app(MasterOrderSplitServiceInterface::class)->splitOrder($orderA);
        $sellerOrderA = $sellerOrders->first();

        $orderB = $this->makeOrder($this->tenantB, Store::create([
            'tenant_id' => $this->tenantB->id, 'name' => 'SI Store B2', 'slug' => 'si-store-b2-'.uniqid(), 'status' => 'active', 'url' => 'https://si-b2.example.com',
        ]), Market::create([
            'tenant_id' => $this->tenantB->id, 'code' => 'IT_'.strtoupper(Str::random(3)), 'name' => 'Italy',
            'default_currency_code' => 'EUR', 'default_locale_code' => 'fr', 'timezone' => 'Europe/Rome', 'is_active' => true,
        ]));

        $paymentB = Payment::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantB->id, 'order_id' => $orderB->id,
            'currency' => 'EUR', 'amount_minor' => 5000, 'status' => 'authorized',
        ]);
        $txB = PaymentTransaction::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantB->id, 'payment_id' => $paymentB->id,
            'operation_type' => 'refund', 'status' => 'success', 'amount_minor' => 5000, 'currency' => 'EUR',
        ]);

        $returnRequestId = DB::table('return_requests')->insertGetId([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id,
            'order_id' => $orderA->id, 'rma_number' => 'RMA-XTEN-'.uniqid(), 'overall_status' => 'requested',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // seller_return belongs to Tenant A, but payment_refund_transaction_id belongs to Tenant B.
        $this->assertRejectedByDatabase(function () use ($returnRequestId, $sellerOrderA, $txB): void {
            DB::table('seller_returns')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenantA->id,
                'return_request_id' => $returnRequestId,
                'seller_order_id' => $sellerOrderA->id,
                'seller_type' => 'platform',
                'vendor_id' => null,
                'seller_rma_number' => 'SRMA-XTEN-'.uniqid(),
                'payment_refund_transaction_id' => $txB->id,
                'reason_code' => 'defective',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'fk_sr_payment_tx_tenant');
    }

    public function test_postgresql_rejects_supplier_invoice_referencing_wrong_purchase_order_supplier(): void
    {
        $supplierX = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'scope_type' => 'tenant',
            'name' => 'Invoice Supplier X', 'code' => 'IX_'.uniqid(), 'contact_email' => 'ix@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);
        $supplierZ = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'scope_type' => 'tenant',
            'name' => 'Invoice Supplier Z', 'code' => 'IZ_'.uniqid(), 'contact_email' => 'iz@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);

        $poId = DB::table('purchase_orders')->insertGetId([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'supplier_id' => $supplierX->id,
            'order_fulfillment_id' => null, 'po_number' => 'PO-INV-'.uniqid(), 'type' => 'dropship', 'status' => 'draft',
            'currency' => 'EUR', 'subtotal_minor' => 1000, 'total_minor' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Invoice claims Supplier Z, but the referenced PurchaseOrder belongs to Supplier X.
        $this->assertRejectedByDatabase(function () use ($supplierZ, $poId): void {
            DB::table('supplier_invoices')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenantA->id,
                'supplier_id' => $supplierZ->id,
                'purchase_order_id' => $poId,
                'invoice_number' => 'INV-XPO-'.uniqid(),
                'currency' => 'EUR',
                'subtotal_minor' => 1000,
                'total_minor' => 1000,
                'issued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'does not match PurchaseOrder supplier');
    }

    public function test_postgresql_rejects_supplier_invoice_line_from_another_purchase_order(): void
    {
        $supplier = Supplier::create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'scope_type' => 'tenant',
            'name' => 'Line Supplier', 'code' => 'LS_'.uniqid(), 'contact_email' => 'ls@example.com', 'status' => 'active', 'currency' => 'EUR',
        ]);

        $poX = DB::table('purchase_orders')->insertGetId([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'supplier_id' => $supplier->id,
            'order_fulfillment_id' => null, 'po_number' => 'PO-LX-'.uniqid(), 'type' => 'dropship', 'status' => 'draft',
            'currency' => 'EUR', 'subtotal_minor' => 1000, 'total_minor' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $poY = DB::table('purchase_orders')->insertGetId([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'supplier_id' => $supplier->id,
            'order_fulfillment_id' => null, 'po_number' => 'PO-LY-'.uniqid(), 'type' => 'dropship', 'status' => 'draft',
            'currency' => 'EUR', 'subtotal_minor' => 1000, 'total_minor' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = DB::table('products')->insertGetId([
            'tenant_id' => $this->tenantA->id, 'sku' => 'SI-PROD-LX-'.uniqid(),
            'product_type' => 'physical', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $lineOnY = DB::table('purchase_order_lines')->insertGetId([
            'tenant_id' => $this->tenantA->id, 'purchase_order_id' => $poY, 'order_item_id' => null, 'product_id' => $product,
            'supplier_sku' => 'LSKU-Y-'.uniqid(), 'internal_sku_snapshot' => 'ISKU-Y', 'quantity' => 1, 'unit_cost_minor' => 500,
            'total_cost_minor' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $invoiceX = DB::table('supplier_invoices')->insertGetId([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenantA->id, 'supplier_id' => $supplier->id,
            'purchase_order_id' => $poX, 'invoice_number' => 'INV-LX-'.uniqid(), 'currency' => 'EUR',
            'subtotal_minor' => 500, 'total_minor' => 500, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Invoice line claims to belong to PO X, but purchase_order_line_id belongs to PO Y.
        $this->assertRejectedByDatabase(function () use ($invoiceX, $poX, $lineOnY): void {
            DB::table('supplier_invoice_lines')->insert([
                'supplier_invoice_id' => $invoiceX,
                'purchase_order_id' => $poX,
                'purchase_order_line_id' => $lineOnY,
                'supplier_sku_snapshot' => 'LSKU-Y',
                'description' => 'Mismatched line',
                'quantity' => 1,
                'unit_cost_minor' => 500,
                'line_total_minor' => 500,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'fk_sil_pol_composite');
    }

    public function test_postgresql_rejects_invalid_hybrid_child_fulfillment_relation(): void
    {
        $orderA = $this->makeOrder($this->tenantA, $this->storeA, $this->marketA);
        $sellerOrders = app(MasterOrderSplitServiceInterface::class)->splitOrder($orderA);
        $sellerOrder = $sellerOrders->first();

        // Parent fulfillment is own_stock (NOT hybrid).
        $nonHybridParentId = DB::table('order_fulfillments')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'seller_order_id' => $sellerOrder->id,
            'parent_fulfillment_id' => null,
            'fulfillment_number' => 'FUL-NONHYB-'.uniqid(),
            'fulfillment_mode' => 'own_stock',
            'status' => 'pending',
            'supplier_id' => null,
            'supplier_location_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to attach a child fulfillment under a non-hybrid parent.
        $this->assertRejectedByDatabase(function () use ($sellerOrder, $nonHybridParentId): void {
            DB::table('order_fulfillments')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenantA->id,
                'seller_order_id' => $sellerOrder->id,
                'parent_fulfillment_id' => $nonHybridParentId,
                'fulfillment_number' => 'FUL-INVALIDCHILD-'.uniqid(),
                'fulfillment_mode' => 'own_stock',
                'status' => 'pending',
                'supplier_id' => null,
                'supplier_location_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'Parent fulfillment mode must be hybrid');
    }
}
