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
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Exceptions\MissingHistoricalCommercialModelException;
use Modules\Order\Exceptions\MissingHistoricalShippingEligibilityException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->splitService = app(MasterOrderSplitServiceInterface::class);

    $this->tenant = Tenant::create([
        'name' => 'Split Tenant',
        'slug' => 'split-tenant',
        'is_active' => true,
    ]);

    $this->store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Split Store',
        'slug' => 'split-store',
        'status' => 'active',
        'url' => 'https://split.example.com',
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
        'handle' => 'web-split',
        'is_active' => true,
    ]);

    $plan = VendorPlan::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Basic Plan',
        'code' => 'basic',
    ]);

    $this->vendor = Vendor::create([
        'tenant_id' => $this->tenant->id,
        'vendor_plan_id' => $plan->id,
        'name' => 'Test Vendor',
        'platform_slug' => 'test-vendor',
        'legal_name' => 'Test Vendor Corp',
        'email' => 'vendor@example.com',
        'payout_currency' => 'EUR',
    ]);
});

function createTestCheckoutSession($test): CheckoutSession
{
    $cart = Cart::create([
        'tenant_id' => $test->tenant->id,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'status' => 'active',
    ]);

    return CheckoutSession::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $test->tenant->id,
        'cart_id' => $cart->id,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'state' => 'ready_for_order',
    ]);
}

test('splits multi-seller order into platform and vendor orders with exact financial conservation', function (): void {
    $session = createTestCheckoutSession($this);

    $order = Order::create([
        'order_number' => 'ORD-1001',
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'market_id' => $this->market->id,
        'channel_id' => $this->channel->id,
        'checkout_id' => $session->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'order_status' => 'placed',
        'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'merchandise_subtotal_minor' => 10000,
        'discount_total_minor' => 1000,
        'tax_total_minor' => 1710,
        'shipping_total_minor' => 600,
        'grand_total_minor' => 11310,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'shipping_snapshot' => [
            'original_amount' => 1000,
            'final_amount' => 600,
            'breakdown' => [
                'promotionDiscount' => 400,
            ],
        ],
        'customer_snapshot' => ['email' => 'test@example.com'],
        'version' => 1,
        'placed_at' => now(),
    ]);

    // Platform line
    OrderItem::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $order->id,
        'sku_snapshot' => 'SKU-PLT',
        'name_snapshot' => 'Platform Product',
        'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => true,
        'quantity' => '1.00000000',
        'unit_price_minor' => 6000,
        'subtotal_minor' => 6000,
        'discount_minor' => 600,
        'tax_minor' => 1026,
        'total_minor' => 6426,
        'vendor_id' => null,
    ]);

    // Vendor line
    OrderItem::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $order->id,
        'sku_snapshot' => 'SKU-VND',
        'name_snapshot' => 'Vendor Product',
        'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => true,
        'quantity' => '1.00000000',
        'unit_price_minor' => 4000,
        'subtotal_minor' => 4000,
        'discount_minor' => 400,
        'tax_minor' => 684,
        'total_minor' => 4284,
        'vendor_id' => $this->vendor->id,
        'commission_amount_minor' => 600,
    ]);

    $sellerOrders = $this->splitService->splitOrder($order);

    expect($sellerOrders)->toHaveCount(2);

    $platformOrder = $sellerOrders->firstWhere('seller_type', 'platform');
    $vendorOrder = $sellerOrders->firstWhere('seller_type', 'vendor');

    expect($platformOrder)->not->toBeNull()
        ->and($vendorOrder)->not->toBeNull()
        ->and($vendorOrder->vendor_id)->toBe($this->vendor->id)
        ->and($platformOrder->seller_order_number)->toBe('ORD-1001-PLT')
        ->and($vendorOrder->seller_order_number)->toBe('ORD-1001-V'.$this->vendor->id);

    // Financial Conservation Assertions
    $sumSubtotal = $sellerOrders->sum('subtotal_minor');
    $sumDiscount = $sellerOrders->sum('discount_minor');
    $sumTax = $sellerOrders->sum('tax_minor');
    $sumShipFinal = $sellerOrders->sum('shipping_final_minor');
    $sumShipOrig = $sellerOrders->sum('shipping_original_minor');
    $sumShipDisc = $sellerOrders->sum('shipping_discount_minor');
    $sumTotal = $sellerOrders->sum('total_minor');

    expect($sumSubtotal)->toBe(10000)
        ->and($sumDiscount)->toBe(1000)
        ->and($sumTax)->toBe(1710)
        ->and($sumShipFinal)->toBe(600)
        ->and($sumShipOrig)->toBe(1000)
        ->and($sumShipDisc)->toBe(400)
        ->and($sumTotal)->toBe(11310);

    // Replay idempotency: calling splitOrder again returns existing SellerOrders
    $replayed = $this->splitService->splitOrder($order);
    expect($replayed)->toHaveCount(2)
        ->and($replayed->pluck('id')->all())->toBe($sellerOrders->pluck('id')->all());
});

test('fails closed if commercial_model_snapshot is missing', function (): void {
    $session = createTestCheckoutSession($this);

    $order = Order::create([
        'order_number' => 'ORD-1002',
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'market_id' => $this->market->id,
        'channel_id' => $this->channel->id,
        'checkout_id' => $session->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'order_status' => 'placed',
        'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'merchandise_subtotal_minor' => 1000,
        'discount_total_minor' => 0,
        'tax_total_minor' => 0,
        'shipping_total_minor' => 0,
        'grand_total_minor' => 1000,
        'commercial_model_snapshot' => null, // MISSING
        'customer_snapshot' => ['email' => 'test@example.com'],
        'version' => 1,
        'placed_at' => now(),
    ]);

    $this->splitService->splitOrder($order);
})->throws(MissingHistoricalCommercialModelException::class);

test('fails closed if requires_shipping_snapshot is missing and shipping > 0', function (): void {
    $session = createTestCheckoutSession($this);

    $order = Order::create([
        'order_number' => 'ORD-1003',
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'market_id' => $this->market->id,
        'channel_id' => $this->channel->id,
        'checkout_id' => $session->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'order_status' => 'placed',
        'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'merchandise_subtotal_minor' => 1000,
        'discount_total_minor' => 0,
        'tax_total_minor' => 0,
        'shipping_total_minor' => 500, // Shipping > 0
        'grand_total_minor' => 1500,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'customer_snapshot' => ['email' => 'test@example.com'],
        'version' => 1,
        'placed_at' => now(),
    ]);

    OrderItem::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $order->id,
        'sku_snapshot' => 'SKU-LEGACY',
        'name_snapshot' => 'Legacy Item',
        'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => null, // MISSING
        'quantity' => '1.00000000',
        'unit_price_minor' => 1000,
        'subtotal_minor' => 1000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 1000,
    ]);

    $this->splitService->splitOrder($order);
})->throws(MissingHistoricalShippingEligibilityException::class);
