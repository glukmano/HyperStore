<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Dropshipping\Contracts\DropshipOrderOrchestratorInterface;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierLocation;
use Modules\Dropshipping\Models\TenantSupplierAccess;
use Modules\Fulfillment\Contracts\FulfillmentExecutionServiceInterface;
use Modules\Fulfillment\Enums\FulfillmentMode;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPayableEntry;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Contracts\ReturnRefundOrchestratorInterface;
use Modules\Order\Contracts\ReturnRequestServiceInterface;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\SellerOrder;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

class PostgreSqlPhase13ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private $tenant;

    private $store;

    private $market;

    private $channel;

    private $vendor;

    private $order;

    private $platformItem;

    private $vendorItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Conc Tenant',
            'slug' => 'conc-tenant-'.Str::random(5),
            'is_active' => true,
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                ],
            ],
        ]);

        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Conc Store',
            'slug' => 'conc-store-'.Str::random(5),
            'status' => 'active',
            'url' => 'https://conc.example.com',
        ]);

        $this->market = Market::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'DE_'.strtoupper(Str::random(3)),
            'name' => 'Germany',
            'default_currency_code' => 'EUR',
            'default_locale_code' => 'de',
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        $this->channel = Channel::create([
            'name' => 'Web Channel',
            'type' => 'website',
            'handle' => 'web-conc-'.Str::random(5),
            'is_active' => true,
        ]);

        $plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Conc Plan',
            'code' => 'conc-plan-'.Str::random(5),
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Conc Vendor',
            'platform_slug' => 'conc-vendor-'.Str::random(5),
            'legal_name' => 'Conc Vendor Corp',
            'email' => 'conc@vendor.com',
            'payout_currency' => 'EUR',
        ]);

        $cart = Cart::create([
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

        $this->order = Order::create([
            'order_number' => 'ORD-CONC-'.strtoupper(Str::random(4)),
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
            'customer_snapshot' => ['email' => 'conc@example.com'],
            'version' => 1,
            'placed_at' => now(),
        ]);

        $this->platformItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $this->order->id,
            'sku_snapshot' => 'SKU-CONC-PLT',
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

        $this->vendorItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $this->order->id,
            'sku_snapshot' => 'SKU-CONC-VND',
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
    }

    public function test_concurrent_master_order_split_is_serialized_and_idempotent(): void
    {
        /** @var MasterOrderSplitServiceInterface $splitService */
        $splitService = app(MasterOrderSplitServiceInterface::class);

        // Execute split concurrently / sequentially within transactions
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $splitService->splitOrder($this->order);
        }

        // Exactly one platform SellerOrder and one vendor SellerOrder exists
        $sellerOrders = SellerOrder::where('order_id', $this->order->id)->get();
        $this->assertCount(2, $sellerOrders);

        $soIds = $sellerOrders->pluck('id')->sort()->values()->all();
        foreach ($results as $res) {
            $this->assertSame($soIds, $res->pluck('id')->sort()->values()->all());
        }
    }

    public function test_concurrent_supplier_deactivation_causes_po_creation_to_fail_closed(): void
    {
        $supplier = Supplier::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'scope_type' => 'tenant',
            'name' => 'Tenant DS',
            'code' => 'T_DS_'.Str::random(4),
            'contact_email' => 'tenant-ds@example.com',
            'status' => 'active',
            'currency' => 'EUR',
        ]);

        $location = SupplierLocation::create([
            'uuid' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'code' => 'LOC-1',
            'name' => 'Loc 1',
            'country_code' => 'DE',
            'city' => 'Munich',
            'postal_code' => '80331',
            'address_line1' => 'St 1',
            'is_active' => true,
        ]);

        $sellerOrders = app(MasterOrderSplitServiceInterface::class)->splitOrder($this->order);
        $pltSo = $sellerOrders->firstWhere('seller_type', 'platform');

        $fulfillments = app(FulfillmentExecutionServiceInterface::class)->createFulfillments($pltSo, [
            [
                'mode' => FulfillmentMode::DROPSHIPPING->value,
                'supplier_id' => $supplier->id,
                'supplier_location_id' => $location->id,
                'items' => [
                    ['order_item_id' => $this->platformItem->id, 'quantity' => '1.00000000'],
                ],
            ],
        ]);

        $f = $fulfillments[0];

        // Simulate concurrent deactivation of supplier
        $supplier->update(['status' => 'inactive']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Supplier [{$supplier->id}] has been deactivated.");

        app(DropshipOrderOrchestratorInterface::class)->createPurchaseOrderForFulfillment($f);
    }

    public function test_concurrent_platform_supplier_tenant_access_disablement_fails_closed(): void
    {
        $supplier = Supplier::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => null,
            'scope_type' => 'platform',
            'name' => 'Global DS',
            'code' => 'GL_DS_'.Str::random(4),
            'contact_email' => 'global-ds@example.com',
            'status' => 'active',
            'currency' => 'EUR',
        ]);

        $location = SupplierLocation::create([
            'uuid' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'code' => 'LOC-GL',
            'name' => 'Loc GL',
            'country_code' => 'DE',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'address_line1' => 'St 2',
            'is_active' => true,
        ]);

        $access = TenantSupplierAccess::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_enabled' => true,
        ]);

        $sellerOrders = app(MasterOrderSplitServiceInterface::class)->splitOrder($this->order);
        $pltSo = $sellerOrders->firstWhere('seller_type', 'platform');

        $fulfillments = app(FulfillmentExecutionServiceInterface::class)->createFulfillments($pltSo, [
            [
                'mode' => FulfillmentMode::DROPSHIPPING->value,
                'supplier_id' => $supplier->id,
                'supplier_location_id' => $location->id,
                'items' => [
                    ['order_item_id' => $this->platformItem->id, 'quantity' => '1.00000000'],
                ],
            ],
        ]);

        $f = $fulfillments[0];

        // Concurrently revoke access
        $access->update(['is_enabled' => false]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Platform supplier [{$supplier->id}] is not enabled for tenant [{$this->tenant->id}].");

        app(DropshipOrderOrchestratorInterface::class)->createPurchaseOrderForFulfillment($f);
    }

    public function test_concurrent_return_approval_cannot_exceed_order_item_quantity(): void
    {
        app(MasterOrderSplitServiceInterface::class)->splitOrder($this->order);

        $returnService = app(ReturnRequestServiceInterface::class);

        // Request return for full 2 units
        $returnRequest = $returnService->createReturnRequest(
            tenantId: $this->tenant->id,
            orderId: $this->order->id,
            customerId: null,
            items: [
                [
                    'order_item_id' => $this->vendorItem->id,
                    'quantity' => '2.00000000',
                    'reason' => 'Wrong size',
                    'condition' => 'unopened',
                ],
            ]
        );

        $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');

        // Approve 1.5 units
        $returnService->approveReturnItem(
            tenantId: $this->tenant->id,
            sellerReturnId: $vendorSr->id,
            orderItemId: $this->vendorItem->id,
            quantityToApprove: '1.50000000'
        );

        // Attempting to approve another 1.0 unit (1.5 + 1.0 = 2.5 > 2.0) must fail closed!
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds OrderItem quantity');

        $returnService->approveReturnItem(
            tenantId: $this->tenant->id,
            sellerReturnId: $vendorSr->id,
            orderItemId: $this->vendorItem->id,
            quantityToApprove: '1.00000000'
        );
    }

    public function test_concurrent_refund_finalization_with_durable_operation_uuid(): void
    {
        app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $this->order->id,
            amountMinor: 11310,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));

        app(MasterOrderSplitServiceInterface::class)->splitOrder($this->order);
        $returnService = app(ReturnRequestServiceInterface::class);

        $returnRequest = $returnService->createReturnRequest(
            tenantId: $this->tenant->id,
            orderId: $this->order->id,
            customerId: null,
            items: [
                [
                    'order_item_id' => $this->vendorItem->id,
                    'quantity' => '1.00000000',
                    'reason' => 'Defective',
                    'condition' => 'unopened',
                ],
            ]
        );

        $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');
        $returnService->approveReturnItem(
            tenantId: $this->tenant->id,
            sellerReturnId: $vendorSr->id,
            orderItemId: $this->vendorItem->id,
            quantityToApprove: '1.00000000'
        );

        $orchestrator = app(ReturnRefundOrchestratorInterface::class);

        // Run 2 finalizeRefund calls
        $res1 = $orchestrator->finalizeRefund($this->tenant->id, $vendorSr->id);
        $res2 = $orchestrator->finalizeRefund($this->tenant->id, $vendorSr->id);

        $this->assertSame($res1->id, $res2->id);
        $this->assertSame($res1->payment_refund_transaction_id, $res2->payment_refund_transaction_id);

        // Exactly one subledger adjustment entry
        $entryCount = VendorPayableEntry::where('tenant_id', $this->tenant->id)
            ->where('vendor_id', $this->vendor->id)
            ->where('entry_type', 'refund_adjustment')
            ->count();

        $this->assertSame(1, $entryCount);
    }
}
