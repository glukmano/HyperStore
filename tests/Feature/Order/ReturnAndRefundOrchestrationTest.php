<?php

declare(strict_types=1);

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPayableEntry;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Contracts\ReturnRefundOrchestratorInterface;
use Modules\Order\Contracts\ReturnRequestServiceInterface;
use Modules\Order\Contracts\ShippingRefundPolicyInterface;
use Modules\Order\Enums\RefundEligibilityStatus;
use Modules\Order\Enums\ReturnRequestStatus;
use Modules\Order\Enums\SellerReturnStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\SellerReturn;
use Modules\Order\Services\ReturnRefundOrchestrator;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentInitiationService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->splitService = app(MasterOrderSplitServiceInterface::class);
    $this->returnService = app(ReturnRequestServiceInterface::class);
    $this->refundOrchestrator = app(ReturnRefundOrchestratorInterface::class);
    $this->paymentInitiationService = app(PaymentInitiationService::class);

    $this->tenant = Tenant::create([
        'name' => 'Return Tenant',
        'slug' => 'ret-tenant',
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
        'name' => 'Return Store',
        'slug' => 'ret-store',
        'status' => 'active',
        'url' => 'https://ret.example.com',
    ]);

    $this->market = Market::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'DE',
        'name' => 'Germany',
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'de',
        'timezone' => 'Europe/Berlin',
        'is_active' => true,
    ]);

    $this->channel = Channel::create([
        'name' => 'Web Channel',
        'type' => 'website',
        'handle' => 'web-ret',
        'is_active' => true,
    ]);

    $plan = VendorPlan::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Return Plan',
        'code' => 'ret-plan',
    ]);

    $this->vendor = Vendor::create([
        'tenant_id' => $this->tenant->id,
        'vendor_plan_id' => $plan->id,
        'name' => 'Return Vendor',
        'platform_slug' => 'ret-vendor',
        'legal_name' => 'Return Vendor Corp',
        'email' => 'ret@vendor.com',
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
        'order_number' => 'ORD-RET-001',
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
        'shipping_total_minor' => 0,
        'grand_total_minor' => 10710,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'customer_snapshot' => ['email' => 'ret@example.com'],
        'version' => 1,
        'placed_at' => now(),
    ]);

    // Platform line
    $this->platformItem = OrderItem::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'sku_snapshot' => 'SKU-RET-PLT',
        'name_snapshot' => 'Platform Product',
        'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => false,
        'quantity' => '2.00000000',
        'unit_price_minor' => 3000,
        'subtotal_minor' => 6000,
        'discount_minor' => 600,
        'tax_minor' => 1026,
        'total_minor' => 6426,
        'vendor_id' => null,
    ]);

    // Vendor line
    $this->vendorItem = OrderItem::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'sku_snapshot' => 'SKU-RET-VND',
        'name_snapshot' => 'Vendor Product',
        'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => false,
        'quantity' => '2.00000000',
        'unit_price_minor' => 2000,
        'subtotal_minor' => 4000,
        'discount_minor' => 400,
        'tax_minor' => 684,
        'total_minor' => 4284,
        'vendor_id' => $this->vendor->id,
        'commission_amount_minor' => 600,
    ]);

    // Split into seller orders
    $this->sellerOrders = $this->splitService->splitOrder($this->order);
});

test('creates multi-seller return request and partitions into seller returns', function (): void {
    $items = [
        [
            'order_item_id' => $this->platformItem->id,
            'quantity' => '1.00000000',
            'reason' => 'Defective',
            'condition' => 'damaged',
        ],
        [
            'order_item_id' => $this->vendorItem->id,
            'quantity' => '1.00000000',
            'reason' => 'Wrong size',
            'condition' => 'unopened',
        ],
    ];

    $returnRequest = $this->returnService->createReturnRequest(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        customerId: null,
        items: $items,
        customerNote: 'Returning parts of order'
    );

    expect($returnRequest)->not->toBeNull()
        ->and($returnRequest->status)->toBe(ReturnRequestStatus::REQUESTED->value)
        ->and($returnRequest->items)->toHaveCount(2)
        ->and($returnRequest->sellerReturns)->toHaveCount(2);

    $platformSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'platform');
    $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');

    expect($platformSr)->not->toBeNull()
        ->and($vendorSr)->not->toBeNull()
        ->and($vendorSr->vendor_id)->toBe($this->vendor->id)
        ->and($platformSr->status)->toBe(SellerReturnStatus::REQUESTED->value)
        ->and($vendorSr->status)->toBe(SellerReturnStatus::REQUESTED->value);
});

test('approves return item with fractional decimal allocation and difference-of-floor math', function (): void {
    $returnRequest = $this->returnService->createReturnRequest(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        customerId: null,
        items: [
            [
                'order_item_id' => $this->vendorItem->id,
                'quantity' => '1.00000000',
                'reason' => 'Wrong item',
                'condition' => 'unopened',
            ],
        ]
    );

    $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');

    // Approve 1 unit
    $updatedSr = $this->returnService->approveReturnItem(
        tenantId: $this->tenant->id,
        sellerReturnId: $vendorSr->id,
        orderItemId: $this->vendorItem->id,
        quantityToApprove: '1.00000000'
    );

    expect($updatedSr->status)->toBe(SellerReturnStatus::APPROVED->value)
        ->and($updatedSr->refund_subtotal_minor)->toBe(2000)
        ->and($updatedSr->refund_discount_reversal_minor)->toBe(200)
        ->and($updatedSr->refund_tax_minor)->toBe(342)
        ->and($updatedSr->vendor_commission_reversal_minor)->toBe(300)
        ->and($updatedSr->net_customer_refund_minor)->toBe(2142)
        ->and($updatedSr->vendor_payable_debit_minor)->toBe(1500)
        ->and($updatedSr->net_customer_refund_minor)->not->toBe($updatedSr->vendor_payable_debit_minor);

    $retItem = $updatedSr->items->firstWhere('order_item_id', $this->vendorItem->id);
    expect($retItem->approved_quantity)->toBe('1.00000000');
});

test('finalizes refund idempotently via payment port and marketplace subledger', function (): void {
    // Initiate captured payment for the order
    $this->paymentInitiationService->initiatePayment(new InitiatePaymentDTO(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        amountMinor: 10710,
        currency: 'EUR',
        providerCode: 'fake',
        captureImmediately: true
    ));

    $returnRequest = $this->returnService->createReturnRequest(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        customerId: null,
        items: [
            [
                'order_item_id' => $this->vendorItem->id,
                'quantity' => '1.00000000',
                'reason' => 'Wrong size',
                'condition' => 'unopened',
            ],
        ]
    );

    $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');
    $this->returnService->approveReturnItem(
        tenantId: $this->tenant->id,
        sellerReturnId: $vendorSr->id,
        orderItemId: $this->vendorItem->id,
        quantityToApprove: '1.00000000'
    );

    // 1. Finalize refund
    $finalized = $this->refundOrchestrator->finalizeRefund(
        tenantId: $this->tenant->id,
        sellerReturnId: $vendorSr->id
    );

    expect($finalized->status)->toBe(SellerReturnStatus::COMPLETED->value)
        ->and($finalized->refund_eligibility_status)->toBe(RefundEligibilityStatus::REFUNDED->value)
        ->and($finalized->refund_status)->toBe('completed')
        ->and($finalized->payment_refund_transaction_id)->not->toBeNull()
        ->and($finalized->refund_finalized_at)->not->toBeNull();

    // Verify marketplace payable entry accrued
    $payableEntry = VendorPayableEntry::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('vendor_id', $this->vendor->id)
        ->where('entry_type', 'refund_adjustment')
        ->first();

    expect($payableEntry)->not->toBeNull()
        ->and($payableEntry->amount_minor)->toBe(1800)
        ->and($payableEntry->commission_amount_minor)->toBe(300)
        ->and($payableEntry->net_amount_minor)->toBe(1500)
        ->and($payableEntry->net_amount_minor)->toBe($finalized->vendor_payable_debit_minor)
        ->and($finalized->net_customer_refund_minor)->toBe(2142)
        ->and($finalized->net_customer_refund_minor)->not->toBe($finalized->vendor_payable_debit_minor);

    // 2. Replay idempotency: second call returns same without re-calling payment or re-debiting payable
    $replay = $this->refundOrchestrator->finalizeRefund(
        tenantId: $this->tenant->id,
        sellerReturnId: $vendorSr->id
    );

    expect($replay->id)->toBe($finalized->id);

    $payableCount = VendorPayableEntry::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('vendor_id', $this->vendor->id)
        ->where('entry_type', 'refund_adjustment')
        ->count();

    expect($payableCount)->toBe(1);
});

test('Test A: normal merchandise return has shipping refund = 0 by explicit ShippingRefundPolicy', function (): void {
    $this->paymentInitiationService->initiatePayment(new InitiatePaymentDTO(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        amountMinor: 10710,
        currency: 'EUR',
        providerCode: 'fake',
        captureImmediately: true
    ));

    $returnRequest = $this->returnService->createReturnRequest(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        customerId: null,
        items: [
            ['order_item_id' => $this->vendorItem->id, 'quantity' => '1.00000000', 'reason' => 'Wrong size', 'condition' => 'unopened'],
        ]
    );
    $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');
    $this->returnService->approveReturnItem(
        tenantId: $this->tenant->id,
        sellerReturnId: $vendorSr->id,
        orderItemId: $this->vendorItem->id,
        quantityToApprove: '1.00000000'
    );

    $finalized = $this->refundOrchestrator->finalizeRefund(tenantId: $this->tenant->id, sellerReturnId: $vendorSr->id);

    // Under the Phase-13 default policy (NOT_REFUNDABLE_BY_DEFAULT), shipping
    // is an explicit typed zero, not an accidental one.
    expect($finalized->refund_shipping_minor)->toBe(0);

    // The actually-executed customer refund transaction must equal exactly
    // merchandise_refund - discount_reversal + tax_refund (2142), proving the
    // zero shipping term was consumed by the formula, not just left unwritten.
    $refundTx = PaymentTransaction::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('id', $finalized->payment_refund_transaction_id)
        ->first();

    expect($refundTx)->not->toBeNull()
        ->and($refundTx->amount_minor)->toBe(2142)
        ->and($finalized->net_customer_refund_minor)->toBe(2142);
});

test('Test B: vendor payable debit never contains shipping, even when a non-zero shipping refund is authorized', function (): void {
    $this->paymentInitiationService->initiatePayment(new InitiatePaymentDTO(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        amountMinor: 10710,
        currency: 'EUR',
        providerCode: 'fake',
        captureImmediately: true
    ));

    $returnRequest = $this->returnService->createReturnRequest(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        customerId: null,
        items: [
            ['order_item_id' => $this->vendorItem->id, 'quantity' => '1.00000000', 'reason' => 'Wrong size', 'condition' => 'unopened'],
        ]
    );
    $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');
    $this->returnService->approveReturnItem(
        tenantId: $this->tenant->id,
        sellerReturnId: $vendorSr->id,
        orderItemId: $this->vendorItem->id,
        quantityToApprove: '1.00000000'
    );

    // Swap in a future/authorized policy that approves a non-zero shipping refund,
    // via the accepted seam (ShippingRefundPolicyInterface), resolving a fresh
    // orchestrator so the override actually takes effect.
    app()->forgetInstance(ReturnRefundOrchestratorInterface::class);
    app()->forgetInstance(ReturnRefundOrchestrator::class);
    app()->singleton(ShippingRefundPolicyInterface::class, fn () => new class implements ShippingRefundPolicyInterface
    {
        public function approvedShippingRefundMinor(SellerReturn $sellerReturn): int
        {
            return 500;
        }
    });
    $overriddenOrchestrator = app(ReturnRefundOrchestratorInterface::class);

    $finalized = $overriddenOrchestrator->finalizeRefund(tenantId: $this->tenant->id, sellerReturnId: $vendorSr->id);

    $payableEntry = VendorPayableEntry::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('vendor_id', $this->vendor->id)
        ->where('entry_type', 'refund_adjustment')
        ->first();

    // Vendor payable debit is computed purely from subtotal/discount/commission
    // reversal and is completely unaffected by the shipping refund authorization.
    expect($payableEntry->net_amount_minor)->toBe(1500)
        ->and($finalized->vendor_payable_debit_minor)->toBe(1500);
});

test('Test C: customer refund formula consumes refund_shipping_minor when a non-zero value is authorized through the policy seam', function (): void {
    $this->paymentInitiationService->initiatePayment(new InitiatePaymentDTO(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        amountMinor: 10710,
        currency: 'EUR',
        providerCode: 'fake',
        captureImmediately: true
    ));

    $returnRequest = $this->returnService->createReturnRequest(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        customerId: null,
        items: [
            ['order_item_id' => $this->vendorItem->id, 'quantity' => '1.00000000', 'reason' => 'Wrong size', 'condition' => 'unopened'],
        ]
    );
    $vendorSr = $returnRequest->sellerReturns->firstWhere('seller_type', 'vendor');
    $this->returnService->approveReturnItem(
        tenantId: $this->tenant->id,
        sellerReturnId: $vendorSr->id,
        orderItemId: $this->vendorItem->id,
        quantityToApprove: '1.00000000'
    );

    app()->forgetInstance(ReturnRefundOrchestratorInterface::class);
    app()->forgetInstance(ReturnRefundOrchestrator::class);
    app()->singleton(ShippingRefundPolicyInterface::class, fn () => new class implements ShippingRefundPolicyInterface
    {
        public function approvedShippingRefundMinor(SellerReturn $sellerReturn): int
        {
            return 500;
        }
    });
    $overriddenOrchestrator = app(ReturnRefundOrchestratorInterface::class);

    $finalized = $overriddenOrchestrator->finalizeRefund(tenantId: $this->tenant->id, sellerReturnId: $vendorSr->id);

    expect($finalized->refund_shipping_minor)->toBe(500)
        ->and($finalized->net_customer_refund_minor)->toBe(2142);

    // customer_refund_minor = merchandise_refund - discount_reversal + tax_refund
    //                        + approved_shipping_refund = 2142 + 500 = 2642
    $refundTx = PaymentTransaction::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('id', $finalized->payment_refund_transaction_id)
        ->first();

    expect($refundTx)->not->toBeNull()
        ->and($refundTx->amount_minor)->toBe(2642);
});
