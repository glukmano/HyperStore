<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
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
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Enums\FulfillmentStatus;
use Modules\Order\Enums\OrderActorType;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\PaymentStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Exceptions\CheckoutNotReadyException;
use Modules\Order\Exceptions\CheckoutReadySnapshotMissingException;
use Modules\Order\Exceptions\ReservationAdoptionFailedException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Pricing\Models\TaxRate;
use Modules\Pricing\Models\TaxZone;
use Modules\Promotions\Models\Coupon;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['name' => 'Order Test Tenant', 'slug' => 'order-tenant', 'status' => 'active']);
    $this->market = Market::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Europe Market',
        'code' => 'EUR-MKT',
        'is_active' => true,
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'en',
        'timezone' => 'Europe/Berlin',
    ]);
    $this->store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Main Store',
        'slug' => 'main-store',
        'status' => 'active',
    ]);
    $this->channel = Channel::create([
        'type' => 'website',
        'name' => 'Web Channel',
        'handle' => 'web-'.uniqid(),
        'is_active' => true,
    ]);
    StoreChannel::create([
        'store_id' => $this->store->id,
        'channel_id' => $this->channel->id,
    ]);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'ORDER-SKU-001',
        translations: [
            'en' => ['name' => 'Test Physical Item'],
            'ar' => ['name' => 'عنصر مادي اختباري'],
        ],
    ));

    $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH-ORD', 'name' => 'Order WH', 'country_code' => 'DE']);
    $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC-ORD', 'name' => 'Order Source', 'priority' => 10]);
    $this->stockItem = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $src->id, 'product_id' => $this->product->id, 'on_hand' => '10.0000', 'reserved' => '0.0000']);

    $this->user = User::factory()->create();

    $this->invService = app(InventoryReservationServiceInterface::class);
    $this->invContext = new InventoryContext(tenantId: $this->tenant->id);

    $this->creationService = app(OrderCreationServiceInterface::class);
});

// Helper to manufacture a valid ready CheckoutSession
function createReadyCheckoutSession($test, ?int $userId = null, int $grandTotal = 5000, array $reservationKeys = [], string $locale = 'en'): CheckoutSession
{
    $uuid = (string) Str::uuid();

    $cart = Cart::create([
        'tenant_id' => $test->tenant->id,
        'user_id' => $userId,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => $locale,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'status' => 'active',
    ]);

    $resRefs = array_map(fn ($k) => ['reservation_key' => $k, 'product_id' => $test->product->id, 'quantity' => '1.00000000'], $reservationKeys);

    $readySnapshot = [
        'checkout_session_id' => 1,
        'checkout_uuid' => $uuid,
        'tenant_id' => $test->tenant->id,
        'cart_id' => $cart->id,
        'cart_version' => 1,
        'context' => [
            'tenant_id' => $test->tenant->id,
            'store_id' => $test->store->id,
            'market_id' => $test->market->id,
            'channel_id' => $test->channel->id,
            'currency' => 'EUR',
            'locale' => $locale,
            'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        ],
        'customer_data' => [
            'email' => 'customer@example.com',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ],
        'shipping_address' => [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address_line_1' => 'Musterstrasse 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country_code' => 'DE',
        ],
        'billing_address' => [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address_line_1' => 'Musterstrasse 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country_code' => 'DE',
        ],
        'lines' => [
            [
                'cart_line_id' => 101,
                'product_id' => $test->product->id,
                'variant_id' => null,
                'sku_snapshot' => 'ORDER-SKU-001',
                'name_snapshot' => 'Test Physical Item',
                'product_type_snapshot' => 'physical',
                'requires_shipping_snapshot' => true,
                'quantity' => '1.00000000',
                'signature' => 'sig-101',
                'selected_options' => ['color' => 'blue'],
                'customization_metadata' => ['engraving' => 'JD'],
            ],
        ],
        'totals' => [
            'merchandise_subtotal' => 4500,
            'line_discounts' => 0,
            'cart_discounts' => 0,
            'shipping_original' => 0,
            'shipping_discount' => 0,
            'shipping_final' => 0,
            'tax_total' => 500,
            'grand_total' => $grandTotal,
            'currency' => 'EUR',
        ],
        'pricing_snapshot' => [
            'lines' => [
                [
                    'cart_line_id' => 101,
                    'product_id' => $test->product->id,
                    'variant_id' => null,
                    'quantity' => '1.00000000',
                    'unit_price_minor' => 4500,
                    'merchandise_line_subtotal_minor' => 4500,
                    'line_discount_minor' => 0,
                    'allocated_cart_discount_minor' => 0,
                    'taxable_amount_minor' => 4500,
                    'tax_minor' => 500,
                    'line_total_minor' => 5000,
                    'tax_class_id' => 1,
                    'tax_rate_percent' => '11.1111',
                    'currency' => 'EUR',
                ],
            ],
            'subtotal_minor' => 4500,
            'currency' => 'EUR',
        ],
        'tax_snapshot' => ['tax_amount_minor' => 500],
        'promotion_snapshot' => ['total_discount_minor' => 0],
        'fulfillment_snapshot' => ['plan' => 'standard'],
        'selected_shipping_quote' => ['method_code' => 'STANDARD', 'cost_minor' => 0],
        'reservation_references' => $resRefs,
        'state' => 'ready_for_order',
        'finalized_at' => Carbon::now()->toIso8601String(),
    ];

    return CheckoutSession::create([
        'uuid' => $uuid,
        'tenant_id' => $test->tenant->id,
        'cart_id' => $cart->id,
        'user_id' => $userId,
        'guest_token_hash' => $userId === null ? hash('sha256', 'guest-token-123') : null,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => $locale,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'state' => 'ready_for_order',
        'customer_data' => $readySnapshot['customer_data'],
        'shipping_address' => $readySnapshot['shipping_address'],
        'billing_address' => $readySnapshot['billing_address'],
        'selected_shipping_quote' => $readySnapshot['selected_shipping_quote'],
        'pricing_snapshot' => $readySnapshot['pricing_snapshot'],
        'tax_snapshot' => $readySnapshot['tax_snapshot'],
        'promotion_snapshot' => $readySnapshot['promotion_snapshot'],
        'reservation_references' => $resRefs,
        'fulfillment_snapshot' => $readySnapshot['fulfillment_snapshot'],
        'ready_snapshot' => $readySnapshot,
        'evaluated_cart_version' => 1,
        'version' => 1,
        'expires_at' => Carbon::now()->addHour(),
    ]);
}

// ---------------------------------------------------------------------------
// 1. Ready checkout produces valid Order with initial statuses
// ---------------------------------------------------------------------------
test('ready checkout produces Order with placed, pending, unfulfilled statuses and dispatches OrderCreated', function (): void {
    Event::fake([OrderCreated::class]);

    $resKey = 'chk-res-'.uniqid();
    $this->invService->reserve($this->tenant->id, $resKey, $this->product->id, null, Quantity::fromString('1.0000'), $this->invContext, 60);

    $checkout = createReadyCheckoutSession($this, userId: $this->user->id, reservationKeys: [$resKey]);

    $result = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
        actorType: OrderActorType::CUSTOMER,
        actorId: $this->user->id
    ));

    expect($result->isReplay)->toBeFalse()
        ->and($result->order)->not->toBeNull()
        ->and($result->order->order_status)->toBe(OrderStatus::PLACED->value)
        ->and($result->order->payment_status)->toBe(PaymentStatus::PENDING->value)
        ->and($result->order->fulfillment_status)->toBe(FulfillmentStatus::UNFULFILLED->value)
        ->and($result->order->grand_total_minor)->toBe(5000)
        ->and($result->order->currency)->toBe('EUR')
        ->and($result->order->checkout_id)->toBe($checkout->id)
        // Owner Delta §10: the effective business timezone is frozen onto
        // the Order at creation time (Market's timezone here is Europe/Berlin).
        ->and($result->order->timezone_snapshot)->toBe('Europe/Berlin');

    // Items
    expect($result->order->items)->toHaveCount(1);
    $item = $result->order->items->first();
    expect($item->sku_snapshot)->toBe('ORDER-SKU-001')
        ->and(bccomp((string) $item->quantity, '1.00000000', 4))->toBe(0)
        ->and($item->unit_price_minor)->toBe(4500)
        ->and($item->subtotal_minor)->toBe(4500)
        ->and($item->tax_minor)->toBe(500)
        ->and($item->total_minor)->toBe(5000)
        ->and($item->tax_class_id)->toBe(1)
        ->and($item->tax_rate_percent)->toBe('11.1111')
        ->and($item->selected_options_snapshot)->toBe(['color' => 'blue']);

    // Status history
    expect($result->order->statusHistory)->toHaveCount(1);
    $hist = $result->order->statusHistory->first();
    expect($hist->from_status)->toBe('none')
        ->and($hist->to_status)->toBe('placed')
        ->and($hist->status_dimension)->toBe('order');

    Event::assertDispatched(OrderCreated::class, function (OrderCreated $event) use ($result) {
        return $event->order->id === $result->order->id
            && ! property_exists($event, 'guestAccessToken');
    });
});

test('changing the Market timezone after Order creation does not change the historical Order timezone (Owner Delta §10)', function (): void {
    $resKey = 'chk-res-tz-'.uniqid();
    $this->invService->reserve($this->tenant->id, $resKey, $this->product->id, null, Quantity::fromString('1.0000'), $this->invContext, 60);
    $checkout = createReadyCheckoutSession($this, userId: $this->user->id, reservationKeys: [$resKey]);

    $result = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
        actorType: OrderActorType::CUSTOMER,
        actorId: $this->user->id
    ));

    expect($result->order->timezone_snapshot)->toBe('Europe/Berlin');
    expect($result->order->displayTimezone()->getName())->toBe('Europe/Berlin');

    // The Market is never hard-deleted (Owner Delta §9) — it can still
    // have its timezone reconfigured while remaining active.
    $this->market->update(['timezone' => 'Asia/Tokyo']);

    $result->order->refresh();
    expect($result->order->timezone_snapshot)->toBe('Europe/Berlin');
    expect($result->order->displayTimezone()->getName())->toBe('Europe/Berlin');
});

// ---------------------------------------------------------------------------
// 2. Non-ready checkout is rejected
// ---------------------------------------------------------------------------
test('non-ready checkout throws CheckoutNotReadyException', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: $this->user->id);
    $checkout->state = 'pricing_evaluated';
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutNotReadyException::class);
});

// ---------------------------------------------------------------------------
// 3. Missing or malformed ready_snapshot fails closed
// ---------------------------------------------------------------------------
test('checkout with missing ready_snapshot throws CheckoutReadySnapshotMissingException', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: $this->user->id);
    $checkout->ready_snapshot = null;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class);
});

test('checkout with malformed ready_snapshot missing lines throws CheckoutReadySnapshotMissingException', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: $this->user->id);
    $snapshot = $checkout->ready_snapshot;
    unset($snapshot['lines']);
    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class);
});

// ---------------------------------------------------------------------------
// 4. Quantity join check: ready vs pricing quantity equality & fractional quantities
// ---------------------------------------------------------------------------
test('quantity mismatch between ready line and pricing line fails closed', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    // Ready line says quantity = 2.00000000, but pricing line says quantity = 1.00000000
    $snapshot['lines'][0]['quantity'] = '2.00000000';
    $snapshot['pricing_snapshot']['lines'][0]['quantity'] = '1.00000000';

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, 'Quantity mismatch for line [101]');

    expect(Order::where('checkout_id', $checkout->id)->count())->toBe(0);
});

test('exact fractional quantities are preserved without float conversion', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    $fractionalQty = '0.12500000';
    $snapshot['lines'][0]['quantity'] = $fractionalQty;
    $snapshot['pricing_snapshot']['lines'][0]['quantity'] = $fractionalQty;

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    $result = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    ));

    $item = $result->order->items->first();
    expect((string) $item->quantity)->toBe('0.12500000');
});

// ---------------------------------------------------------------------------
// 5. CheckoutTotals Required Keys & Currency Validation
// ---------------------------------------------------------------------------
test('missing required CheckoutTotals keys fail closed', function (string $missingKey): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;
    unset($snapshot['totals'][$missingKey]);

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, "missing required key [{$missingKey}]");
})->with([
    'merchandise_subtotal',
    'line_discounts',
    'cart_discounts',
    'shipping_original',
    'shipping_discount',
    'shipping_final',
    'tax_total',
    'grand_total',
    'currency',
]);

test('currency mismatch between context, totals and pricing lines fails closed', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    // Mismatch in totals
    $snapshot['totals']['currency'] = 'USD';
    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, 'Currency mismatch');
});

// ---------------------------------------------------------------------------
// 6. Strict Canonical Pricing-Line Required-Key Validation (Blocker 2)
// ---------------------------------------------------------------------------
test('missing required canonical pricing line fields fail closed', function (string $missingField): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    unset($snapshot['pricing_snapshot']['lines'][0][$missingField]);

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, "missing required key [{$missingField}]");
})->with([
    'line_discount_minor',
    'allocated_cart_discount_minor',
    'taxable_amount_minor',
    'tax_minor',
    'merchandise_line_subtotal_minor',
    'unit_price_minor',
    'line_total_minor',
    'currency',
]);

// ---------------------------------------------------------------------------
// 7. Complete Reconciliation Tests (Merchandise, Discounts, Taxes, Grand Total)
// ---------------------------------------------------------------------------
test('line tax sum mismatch with totals.tax_total fails closed', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    // Line tax is 500, but header tax_total is 600
    $snapshot['totals']['tax_total'] = 600;
    $snapshot['totals']['grand_total'] = 5100;
    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, 'Sum of line taxes [500] does not match tax_total [600]');
});

test('line total calculation mismatch fails closed', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    // Line: subtotal=4500, discount=0, tax=500. Expected total=5000. Force line_total_minor=5500
    $snapshot['pricing_snapshot']['lines'][0]['merchandise_line_subtotal_minor'] = 4500;
    $snapshot['pricing_snapshot']['lines'][0]['line_total_minor'] = 5500;
    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, 'Line total calculation mismatch');
});

// ---------------------------------------------------------------------------
// 8. Exact Line Join Tests (Cases A - E)
// ---------------------------------------------------------------------------
test('case a: same product_id with different variants joins pricing exactly by cart_line_id', function (): void {
    $v1 = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'VAR-1', 'combination_hash' => 'h1', 'status' => 'active']);
    $v2 = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'VAR-2', 'combination_hash' => 'h2', 'status' => 'active']);

    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    $snapshot['lines'] = [
        [
            'cart_line_id' => 201,
            'product_id' => $this->product->id,
            'variant_id' => $v1->id,
            'sku_snapshot' => 'VAR-1',
            'name_snapshot' => 'Variant 1 Item',
            'product_type_snapshot' => 'physical',
            'requires_shipping_snapshot' => true,
            'quantity' => '1.00000000',
        ],
        [
            'cart_line_id' => 202,
            'product_id' => $this->product->id,
            'variant_id' => $v2->id,
            'sku_snapshot' => 'VAR-2',
            'name_snapshot' => 'Variant 2 Item',
            'product_type_snapshot' => 'physical',
            'requires_shipping_snapshot' => true,
            'quantity' => '2.00000000',
        ],
    ];

    $snapshot['pricing_snapshot']['lines'] = [
        [
            'cart_line_id' => 201,
            'product_id' => $this->product->id,
            'variant_id' => $v1->id,
            'quantity' => '1.00000000',
            'unit_price_minor' => 1000,
            'merchandise_line_subtotal_minor' => 1000,
            'line_discount_minor' => 0,
            'allocated_cart_discount_minor' => 0,
            'taxable_amount_minor' => 1000,
            'tax_minor' => 0,
            'line_total_minor' => 1000,
            'tax_class_id' => null,
            'tax_rate_percent' => null,
            'currency' => 'EUR',
        ],
        [
            'cart_line_id' => 202,
            'product_id' => $this->product->id,
            'variant_id' => $v2->id,
            'quantity' => '2.00000000',
            'unit_price_minor' => 1500,
            'merchandise_line_subtotal_minor' => 3000,
            'line_discount_minor' => 0,
            'allocated_cart_discount_minor' => 0,
            'taxable_amount_minor' => 3000,
            'tax_minor' => 0,
            'line_total_minor' => 3000,
            'tax_class_id' => null,
            'tax_rate_percent' => null,
            'currency' => 'EUR',
        ],
    ];

    $snapshot['totals']['merchandise_subtotal'] = 4000;
    $snapshot['totals']['tax_total'] = 0;
    $snapshot['totals']['grand_total'] = 4000;

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    $result = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    ));

    expect($result->order->items)->toHaveCount(2);
    $item1 = $result->order->items->firstWhere('variant_id', $v1->id);
    $item2 = $result->order->items->firstWhere('variant_id', $v2->id);

    expect($item1->unit_price_minor)->toBe(1000)
        ->and($item1->subtotal_minor)->toBe(1000)
        ->and($item2->unit_price_minor)->toBe(1500)
        ->and($item2->subtotal_minor)->toBe(3000);
});

test('case b: same product and variant with different options on separate lines joins exactly', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    $snapshot['lines'] = [
        [
            'cart_line_id' => 301,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'sku_snapshot' => 'ORDER-SKU-001',
            'name_snapshot' => 'Item Custom Red',
            'product_type_snapshot' => 'physical',
            'requires_shipping_snapshot' => true,
            'quantity' => '1.00000000',
            'selected_options' => ['color' => 'red'],
        ],
        [
            'cart_line_id' => 302,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'sku_snapshot' => 'ORDER-SKU-001',
            'name_snapshot' => 'Item Custom Green',
            'product_type_snapshot' => 'physical',
            'requires_shipping_snapshot' => true,
            'quantity' => '1.00000000',
            'selected_options' => ['color' => 'green'],
        ],
    ];

    $snapshot['pricing_snapshot']['lines'] = [
        [
            'cart_line_id' => 301,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'quantity' => '1.00000000',
            'unit_price_minor' => 2000,
            'merchandise_line_subtotal_minor' => 2000,
            'line_discount_minor' => 0,
            'allocated_cart_discount_minor' => 0,
            'taxable_amount_minor' => 2000,
            'tax_minor' => 0,
            'line_total_minor' => 2000,
            'tax_class_id' => null,
            'tax_rate_percent' => null,
            'currency' => 'EUR',
        ],
        [
            'cart_line_id' => 302,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'quantity' => '1.00000000',
            'unit_price_minor' => 2500,
            'merchandise_line_subtotal_minor' => 2500,
            'line_discount_minor' => 0,
            'allocated_cart_discount_minor' => 0,
            'taxable_amount_minor' => 2500,
            'tax_minor' => 0,
            'line_total_minor' => 2500,
            'tax_class_id' => null,
            'tax_rate_percent' => null,
            'currency' => 'EUR',
        ],
    ];

    $snapshot['totals']['merchandise_subtotal'] = 4500;
    $snapshot['totals']['tax_total'] = 0;
    $snapshot['totals']['grand_total'] = 4500;

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    $result = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    ));

    expect($result->order->items)->toHaveCount(2);
    $redItem = $result->order->items->firstWhere('selected_options_snapshot.color', 'red');
    $greenItem = $result->order->items->firstWhere('selected_options_snapshot.color', 'green');

    expect($redItem->unit_price_minor)->toBe(2000)
        ->and($greenItem->unit_price_minor)->toBe(2500);
});

test('case c: pricing line missing for required ready line fails closed', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    $snapshot['lines'][] = [
        'cart_line_id' => 999,
        'product_id' => $this->product->id,
        'variant_id' => null,
        'quantity' => '1.00000000',
    ];

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, 'Pricing line missing for required ready line [999]');
});

test('case d: duplicate pricing line identity in pricing snapshot fails closed', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    $pLine = $snapshot['pricing_snapshot']['lines'][0];
    $snapshot['pricing_snapshot']['lines'][] = $pLine;

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, 'Duplicate pricing line identity [101]');
});

test('case e: orphan pricing line not represented in ready lines fails closed', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    $snapshot['pricing_snapshot']['lines'][] = [
        'cart_line_id' => 888,
        'product_id' => $this->product->id,
        'variant_id' => null,
        'quantity' => '1.00000000',
        'unit_price_minor' => 500,
        'merchandise_line_subtotal_minor' => 500,
        'line_discount_minor' => 0,
        'allocated_cart_discount_minor' => 0,
        'taxable_amount_minor' => 500,
        'tax_minor' => 0,
        'line_total_minor' => 500,
        'tax_class_id' => null,
        'tax_rate_percent' => null,
        'currency' => 'EUR',
    ];

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, 'Orphan pricing line identity [888]');
});

// ---------------------------------------------------------------------------
// 9. Authoritative Locale Handoff Tests
// ---------------------------------------------------------------------------
test('authoritative locale handoff preserves arabic and fails closed on missing locale', function (): void {
    // Arabic checkout
    $arCheckout = createReadyCheckoutSession($this, userId: null, locale: 'ar');
    $arResult = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $arCheckout->id,
    ));
    expect($arResult->order->locale)->toBe('ar');

    // English checkout
    $enCheckout = createReadyCheckoutSession($this, userId: null, locale: 'en');
    $enResult = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $enCheckout->id,
    ));
    expect($enResult->order->locale)->toBe('en');

    // Missing locale fails closed
    $badCheckout = createReadyCheckoutSession($this, userId: null);
    $snap = $badCheckout->ready_snapshot;
    unset($snap['context']['locale']);
    $badCheckout->ready_snapshot = $snap;
    $badCheckout->save();

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $badCheckout->id,
    )))->toThrow(CheckoutReadySnapshotMissingException::class, 'Missing or empty authoritative context [locale]');
});

// ---------------------------------------------------------------------------
// 10. Genuine Zero Grand Total Order Through REAL Production Pipeline (Blocker 3)
// ---------------------------------------------------------------------------
test('real cart to checkout to order zero grand total integration test through production pipeline', function (): void {
    // 1. Setup Currency and non-taxable physical product in Germany
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $taxClassZero = TaxClass::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'ZERO_TAX',
        'name' => 'Zero Tax Rate',
        'is_default' => true,
    ]);

    $taxZoneDe = TaxZone::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'DE_ZERO_TZ',
        'name' => 'Germany Zero Zone',
        'country_code' => 'DE',
        'priority' => 10,
    ]);

    TaxRate::create([
        'tenant_id' => $this->tenant->id,
        'tax_class_id' => $taxClassZero->id,
        'tax_zone_id' => $taxZoneDe->id,
        'name' => 'German 0.0000% VAT',
        'rate_percentage' => '0.0000',
        'priority' => 0,
    ]);

    $zeroTaxProduct = Product::create([
        'tenant_id' => $this->tenant->id,
        'sku' => 'ZERO-PROD-001',
        'name' => 'Zero Tax Product',
        'slug' => 'zero-tax-prod',
        'product_type' => 'physical',
        'status' => 'active',
        'tax_class_id' => $taxClassZero->id,
    ]);

    $wh = Warehouse::firstOrCreate(['tenant_id' => $this->tenant->id, 'code' => 'WH-ORD'], ['name' => 'Order WH', 'country_code' => 'DE']);
    $src = InventorySource::firstOrCreate(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC-ORD'], ['name' => 'Order Source', 'priority' => 10]);
    StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $src->id, 'product_id' => $zeroTaxProduct->id, 'on_hand' => 50, 'reserved' => 0]);

    $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'EUR_STD', 'name' => 'EUR Std', 'currency' => 'EUR', 'status' => 'active', 'priority' => 1]);
    Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $zeroTaxProduct->id, 'amount_minor' => 3000, 'currency' => 'EUR', 'status' => 'active']);

    $shippingMethod = ShippingMethod::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'FREE_SHIPPING',
        'name' => 'Free Delivery',
        'rate_calculator_type' => 'flat_rate',
        'currency' => 'EUR',
        'base_amount' => 0,
        'status' => 'active',
    ]);
    $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'DE_ZONE', 'name' => 'DE Zone', 'status' => 'active']);
    ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'DE']);
    ShippingMethodZone::create(['shipping_method_id' => $shippingMethod->id, 'shipping_zone_id' => $zone->id]);

    // 2. Setup 100% discount Coupon & Promotion
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '100% Free Promo',
        'code' => 'PROMO_FREE100',
        'status' => 'active',
        'priority' => 1,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 100],
    ]);
    Coupon::create([
        'tenant_id' => $this->tenant->id,
        'promotion_id' => $promo->id,
        'code' => 'FREE100',
        'status' => 'active',
    ]);

    // 3. Create Cart via CartService
    $cartService = app(CartServiceInterface::class);
    $cart = $cartService->getOrCreateActiveCart(new CartContext(
        tenantId: $this->tenant->id,
        storeId: $this->store->id,
        marketId: $this->market->id,
        channelId: $this->channel->id,
        currency: 'EUR',
        userId: $this->user->id
    ));
    $cartService->addLine($cart, new CartLineItemData(
        productId: $zeroTaxProduct->id,
        variantId: null,
        quantity: CartQuantity::fromInt(1)
    ));

    // 4. Run real Checkout pipeline
    $orchestrator = app(CheckoutOrchestratorInterface::class);
    $session = $orchestrator->createFromCart($cart);
    $orchestrator->setCustomerData($session, new CheckoutCustomerData('free@example.com', 'Zero', 'Customer'));
    $orchestrator->setAddresses(
        $session,
        new CheckoutAddress('Zero Customer', ['Teststrasse 1'], 'Berlin', 'DE', postalCode: '10115')
    );
    $orchestrator->applyCoupon($session, 'FREE100');
    $orchestrator->selectShippingQuote($session, [
        'method_id' => $shippingMethod->id,
        'method_code' => $shippingMethod->code,
    ]);
    $session = $orchestrator->reserveInventory($session);

    // 5. Produce authoritative ready_snapshot WITHOUT manual tampering
    $orchestrator->markReadyForOrder($session);

    $freshSession = CheckoutSession::find($session->id);
    expect($freshSession->state)->toBe('ready_for_order')
        ->and($freshSession->ready_snapshot)->not->toBeNull()
        ->and($freshSession->ready_snapshot['totals']['merchandise_subtotal'])->toBe(3000)
        ->and($freshSession->ready_snapshot['totals']['cart_discounts'])->toBe(3000)
        ->and($freshSession->ready_snapshot['totals']['shipping_final'])->toBe(0)
        ->and($freshSession->ready_snapshot['totals']['tax_total'])->toBe(0)
        ->and($freshSession->ready_snapshot['totals']['grand_total'])->toBe(0);

    // 6. Create Order from real Checkout
    $result = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $freshSession->id,
        actorType: OrderActorType::CUSTOMER,
        actorId: $this->user->id
    ));

    expect($result->order)->not->toBeNull()
        ->and($result->order->grand_total_minor)->toBe(0)
        ->and($result->order->merchandise_subtotal_minor)->toBe(3000)
        ->and($result->order->discount_total_minor)->toBe(3000)
        ->and($result->order->order_status)->toBe(OrderStatus::PLACED->value)
        ->and($result->order->payment_status)->toBe(PaymentStatus::PENDING->value)
        ->and($result->order->fulfillment_status)->toBe(FulfillmentStatus::UNFULFILLED->value);
});

test('strict snapshot validator accepts zero grand total with matching line discounts', function (): void {
    $checkout = createReadyCheckoutSession($this, userId: null);
    $snapshot = $checkout->ready_snapshot;

    $snapshot['totals'] = [
        'merchandise_subtotal' => 4500,
        'line_discounts' => 0,
        'cart_discounts' => 4500,
        'shipping_original' => 0,
        'shipping_discount' => 0,
        'shipping_final' => 0,
        'tax_total' => 0,
        'grand_total' => 0,
        'currency' => 'EUR',
    ];

    $snapshot['pricing_snapshot']['lines'][0]['allocated_cart_discount_minor'] = 4500;
    $snapshot['pricing_snapshot']['lines'][0]['taxable_amount_minor'] = 0;
    $snapshot['pricing_snapshot']['lines'][0]['tax_minor'] = 0;
    $snapshot['pricing_snapshot']['lines'][0]['line_total_minor'] = 0;

    $checkout->ready_snapshot = $snapshot;
    $checkout->save();

    $result = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    ));

    expect($result->order->grand_total_minor)->toBe(0)
        ->and($result->order->merchandise_subtotal_minor)->toBe(4500)
        ->and($result->order->discount_total_minor)->toBe(4500)
        ->and($result->order->order_status)->toBe(OrderStatus::PLACED->value)
        ->and($result->order->payment_status)->toBe(PaymentStatus::PENDING->value)
        ->and($result->order->fulfillment_status)->toBe(FulfillmentStatus::UNFULFILLED->value);
});

// ---------------------------------------------------------------------------
// 11. Reservation adoption & rollback tests
// ---------------------------------------------------------------------------
test('all reservation references are adopted with owner_reference equal to Order uuid', function (): void {
    $resKey = 'adopt-order-'.uniqid();
    $this->invService->reserve($this->tenant->id, $resKey, $this->product->id, null, Quantity::fromString('1.0000'), $this->invContext, 60);

    $stockReservedBefore = $this->stockItem->fresh()->reserved;

    $checkout = createReadyCheckoutSession($this, userId: $this->user->id, reservationKeys: [$resKey]);

    $result = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    ));

    $res = InventoryReservation::where('reservation_key', $resKey)->first();
    expect($res->owner_type)->toBe('order')
        ->and($res->owner_reference)->toBe($result->order->uuid)
        ->and($res->expires_at)->toBeNull()
        ->and($res->status)->toBe('active');

    expect($this->stockItem->fresh()->reserved)->toBe($stockReservedBefore);
});

test('failed reservation adoption rolls back order creation and leaves no partial Order', function (): void {
    $resKey = 'non-existent-res-key';

    $checkout = createReadyCheckoutSession($this, userId: $this->user->id, reservationKeys: [$resKey]);

    expect(fn () => $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    )))->toThrow(ReservationAdoptionFailedException::class);

    expect(Order::where('checkout_id', $checkout->id)->count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 12. Retry / Replay returns existing semantic Order
// ---------------------------------------------------------------------------
test('retry order creation returns existing semantic order with isReplay true', function (): void {
    $resKey = 'replay-res-'.uniqid();
    $this->invService->reserve($this->tenant->id, $resKey, $this->product->id, null, Quantity::fromString('1.0000'), $this->invContext, 60);

    $checkout = createReadyCheckoutSession($this, userId: $this->user->id, reservationKeys: [$resKey]);

    $first = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    ));

    $second = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
    ));

    expect($first->isReplay)->toBeFalse()
        ->and($second->isReplay)->toBeTrue()
        ->and($second->order->id)->toBe($first->order->id)
        ->and($second->order->order_number)->toBe($first->order->order_number)
        ->and(Order::where('checkout_id', $checkout->id)->count())->toBe(1);
});

// ===========================================================================
// 13. Comprehensive Proportional Cart Discount & Discount-Aware Tax Tests (ADR-0092)
// ===========================================================================

/**
 * Helper to build a complete commercial pipeline product with stock and price.
 */
function createPipelineProduct(
    object $test,
    int $priceMinor,
    TaxClass $taxClass,
    string $sku,
    string $slug
): Product {
    $product = Product::create([
        'tenant_id' => $test->tenant->id,
        'sku' => $sku,
        'name' => "Product {$sku}",
        'slug' => $slug,
        'product_type' => 'physical',
        'status' => 'active',
        'tax_class_id' => $taxClass->id,
        'metadata' => ['tax_class_id' => $taxClass->id],
    ]);

    $wh = Warehouse::firstOrCreate(
        ['tenant_id' => $test->tenant->id, 'code' => 'WH-ORD-PIPELINE'],
        ['name' => 'Order Pipeline WH', 'country_code' => 'DE']
    );
    $src = InventorySource::firstOrCreate(
        ['tenant_id' => $test->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC-ORD-PIPELINE'],
        ['name' => 'Order Pipeline Source', 'priority' => 10]
    );
    StockItem::create([
        'tenant_id' => $test->tenant->id,
        'inventory_source_id' => $src->id,
        'product_id' => $product->id,
        'on_hand' => 100,
        'reserved' => 0,
    ]);

    $pb = PriceBook::firstOrCreate(
        ['tenant_id' => $test->tenant->id, 'code' => 'EUR_STD_PIPELINE'],
        ['name' => 'EUR Std Pipeline', 'currency' => 'EUR', 'status' => 'active', 'priority' => 1]
    );
    Price::create([
        'tenant_id' => $test->tenant->id,
        'price_book_id' => $pb->id,
        'product_id' => $product->id,
        'amount_minor' => $priceMinor,
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    return $product;
}

/**
 * Helper to ensure flat free shipping exists for DE.
 */
function ensureFreeShippingMethod(object $test): ShippingMethod
{
    $zone = ShippingZone::firstOrCreate(
        ['tenant_id' => $test->tenant->id, 'code' => 'DE_PIPE_ZONE'],
        ['name' => 'DE Pipe Zone', 'status' => 'active']
    );
    ShippingZoneRule::firstOrCreate([
        'shipping_zone_id' => $zone->id,
        'rule_type' => 'country',
        'country_code' => 'DE',
    ]);

    $method = ShippingMethod::firstOrCreate(
        ['tenant_id' => $test->tenant->id, 'code' => 'FREE_PIPE_SHIP'],
        [
            'name' => 'Free Pipe Shipping',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'EUR',
            'base_amount' => 0,
            'status' => 'active',
        ]
    );
    ShippingMethodZone::firstOrCreate([
        'shipping_method_id' => $method->id,
        'shipping_zone_id' => $zone->id,
    ]);

    return $method;
}

test('TEST A: taxable product with no discount calculates authoritative tax and reconciles', function (): void {
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $taxClass = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT19_A', 'name' => '19% VAT', 'is_default' => true]);
    $tz = TaxZone::create(['tenant_id' => $this->tenant->id, 'code' => 'TZ_DE_A', 'name' => 'DE Zone A', 'country_code' => 'DE', 'priority' => 10]);
    TaxRate::create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $taxClass->id, 'tax_zone_id' => $tz->id, 'name' => '19% VAT Rate', 'rate_percentage' => '19.0000', 'priority' => 0]);

    $product = createPipelineProduct($this, 10000, $taxClass, 'PROD-A', 'prod-a');
    $shippingMethod = ensureFreeShippingMethod($this);

    $cartService = app(CartServiceInterface::class);
    $cart = $cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $cartService->addLine($cart, new CartLineItemData($product->id, null, CartQuantity::fromInt(1)));

    $checkoutOrch = app(CheckoutOrchestratorInterface::class);
    $session = $checkoutOrch->createFromCart($cart);
    $session = $checkoutOrch->setCustomerData($session, new CheckoutCustomerData('a@example.com', 'Alice', 'Tester'));
    $session = $checkoutOrch->setAddresses($session, new CheckoutAddress('Alice', ['Alexanderplatz 1'], 'Berlin', 'DE', postalCode: '10178'));

    $session = $checkoutOrch->selectShippingQuote($session, ['method_id' => $shippingMethod->id, 'method_code' => $shippingMethod->code]);
    $session = $checkoutOrch->reserveInventory($session);
    $ready = $checkoutOrch->markReadyForOrder($session);

    $orderResult = $this->creationService->createFromCheckout(new OrderCreationDTO($this->tenant->id, $session->id));
    $order = $orderResult->order;

    // Subtotal: 10000, Tax (19% of 10000): 1900, Grand Total: 11900
    expect($order->merchandise_subtotal_minor)->toBe(10000)
        ->and($order->discount_total_minor)->toBe(0)
        ->and($order->tax_total_minor)->toBe(1900)
        ->and($order->grand_total_minor)->toBe(11900)
        ->and($order->items)->toHaveCount(1);

    $item = $order->items->first();
    expect($item->subtotal_minor)->toBe(10000)
        ->and($item->allocated_cart_discount_minor)->toBe(0)
        ->and($item->taxable_amount_minor)->toBe(10000)
        ->and($item->tax_minor)->toBe(1900)
        ->and($item->total_minor)->toBe(11900);
});

test('TEST B: taxable product with 20% cart discount calculates tax on discounted taxable base', function (): void {
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $taxClass = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT19_B', 'name' => '19% VAT', 'is_default' => true]);
    $tz = TaxZone::create(['tenant_id' => $this->tenant->id, 'code' => 'TZ_DE_B', 'name' => 'DE Zone B', 'country_code' => 'DE', 'priority' => 10]);
    TaxRate::create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $taxClass->id, 'tax_zone_id' => $tz->id, 'name' => '19% VAT Rate', 'rate_percentage' => '19.0000', 'priority' => 0]);

    $product = createPipelineProduct($this, 10000, $taxClass, 'PROD-B', 'prod-b');
    $shippingMethod = ensureFreeShippingMethod($this);

    // 20% discount coupon
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '20% Off Promo',
        'code' => 'PROMO_20',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 20],
    ]);
    Coupon::create(['tenant_id' => $this->tenant->id, 'promotion_id' => $promo->id, 'code' => 'SAVE20', 'status' => 'active']);

    $cartService = app(CartServiceInterface::class);
    $cart = $cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $cartService->addLine($cart, new CartLineItemData($product->id, null, CartQuantity::fromInt(1)));

    $checkoutOrch = app(CheckoutOrchestratorInterface::class);
    $session = $checkoutOrch->createFromCart($cart);
    $session = $checkoutOrch->setCustomerData($session, new CheckoutCustomerData('b@example.com', 'Bob', 'Tester'));
    $session = $checkoutOrch->setAddresses($session, new CheckoutAddress('Bob', ['Kurfürstendamm 1'], 'Berlin', 'DE', postalCode: '10719'));
    $session = $checkoutOrch->applyCoupon($session, 'SAVE20');

    $session = $checkoutOrch->selectShippingQuote($session, ['method_id' => $shippingMethod->id, 'method_code' => $shippingMethod->code]);
    $session = $checkoutOrch->reserveInventory($session);
    $ready = $checkoutOrch->markReadyForOrder($session);

    $orderResult = $this->creationService->createFromCheckout(new OrderCreationDTO($this->tenant->id, $session->id));
    $order = $orderResult->order;

    // Subtotal: 10000, Cart Discount: 2000, Taxable Base: 8000, Tax (19% of 8000): 1520, Grand Total: 9520
    expect($order->merchandise_subtotal_minor)->toBe(10000)
        ->and($order->discount_total_minor)->toBe(2000)
        ->and($order->tax_total_minor)->toBe(1520)
        ->and($order->grand_total_minor)->toBe(9520);

    $item = $order->items->first();
    expect($item->subtotal_minor)->toBe(10000)
        ->and($item->allocated_cart_discount_minor)->toBe(2000)
        ->and($item->taxable_amount_minor)->toBe(8000)
        ->and($item->tax_minor)->toBe(1520)
        ->and($item->total_minor)->toBe(9520);
});

test('TEST C: two lines 7000 + 3000 with 1000 cart discount allocates 700 and 300 exactly', function (): void {
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $taxClass = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT19_C', 'name' => '19% VAT', 'is_default' => true]);
    $tz = TaxZone::create(['tenant_id' => $this->tenant->id, 'code' => 'TZ_DE_C', 'name' => 'DE Zone C', 'country_code' => 'DE', 'priority' => 10]);
    TaxRate::create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $taxClass->id, 'tax_zone_id' => $tz->id, 'name' => '19% VAT Rate', 'rate_percentage' => '19.0000', 'priority' => 0]);

    $p1 = createPipelineProduct($this, 7000, $taxClass, 'PROD-C1', 'prod-c1');
    $p2 = createPipelineProduct($this, 3000, $taxClass, 'PROD-C2', 'prod-c2');
    $shippingMethod = ensureFreeShippingMethod($this);

    // Fixed 1000 minor (10 EUR) discount
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '10 EUR Off',
        'code' => 'PROMO_10',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 1000, 'currency' => 'EUR'],
    ]);
    Coupon::create(['tenant_id' => $this->tenant->id, 'promotion_id' => $promo->id, 'code' => '10OFF', 'status' => 'active']);

    $cartService = app(CartServiceInterface::class);
    $cart = $cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $cartService->addLine($cart, new CartLineItemData($p1->id, null, CartQuantity::fromInt(1)));
    $cartService->addLine($cart, new CartLineItemData($p2->id, null, CartQuantity::fromInt(1)));

    $checkoutOrch = app(CheckoutOrchestratorInterface::class);
    $session = $checkoutOrch->createFromCart($cart);
    $session = $checkoutOrch->setCustomerData($session, new CheckoutCustomerData('c@example.com', 'Charlie', 'Tester'));
    $session = $checkoutOrch->setAddresses($session, new CheckoutAddress('Charlie', ['Friedrichstrasse 1'], 'Berlin', 'DE', postalCode: '10117'));
    $session = $checkoutOrch->applyCoupon($session, '10OFF');

    $session = $checkoutOrch->selectShippingQuote($session, ['method_id' => $shippingMethod->id, 'method_code' => $shippingMethod->code]);
    $session = $checkoutOrch->reserveInventory($session);
    $ready = $checkoutOrch->markReadyForOrder($session);

    $orderResult = $this->creationService->createFromCheckout(new OrderCreationDTO($this->tenant->id, $session->id));
    $order = $orderResult->order;

    // Line 1: 7000 subtotal, 700 discount, 6300 taxable, 19% tax = 1197, total = 7497
    // Line 2: 3000 subtotal, 300 discount, 2700 taxable, 19% tax = 513, total = 3213
    // Header: subtotal 10000, discount 1000, tax 1710, grand total 10710
    expect($order->merchandise_subtotal_minor)->toBe(10000)
        ->and($order->discount_total_minor)->toBe(1000)
        ->and($order->tax_total_minor)->toBe(1710)
        ->and($order->grand_total_minor)->toBe(10710);

    $item1 = $order->items->firstWhere('product_id', $p1->id);
    $item2 = $order->items->firstWhere('product_id', $p2->id);

    expect($item1->subtotal_minor)->toBe(7000)
        ->and($item1->allocated_cart_discount_minor)->toBe(700)
        ->and($item1->taxable_amount_minor)->toBe(6300)
        ->and($item1->tax_minor)->toBe(1197)
        ->and($item1->total_minor)->toBe(7497);

    expect($item2->subtotal_minor)->toBe(3000)
        ->and($item2->allocated_cart_discount_minor)->toBe(300)
        ->and($item2->taxable_amount_minor)->toBe(2700)
        ->and($item2->tax_minor)->toBe(513)
        ->and($item2->total_minor)->toBe(3213);
});

test('TEST D & E: rounding and equal remainder tie breaks deterministically by cart_line_id ascending', function (): void {
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $taxClass = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT0_D', 'name' => '0% VAT', 'is_default' => true]);
    $p1 = createPipelineProduct($this, 1000, $taxClass, 'PROD-D1', 'prod-d1');
    $p2 = createPipelineProduct($this, 1000, $taxClass, 'PROD-D2', 'prod-d2');
    $p3 = createPipelineProduct($this, 1000, $taxClass, 'PROD-D3', 'prod-d3');
    $shippingMethod = ensureFreeShippingMethod($this);

    // 100 minor discount on 3000 total (3 lines of 1000 each)
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '100 Minor Discount',
        'code' => 'PROMO_100M',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 100, 'currency' => 'EUR'],
    ]);
    Coupon::create(['tenant_id' => $this->tenant->id, 'promotion_id' => $promo->id, 'code' => '100CENTS', 'status' => 'active']);

    $cartService = app(CartServiceInterface::class);
    $cart = $cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $line1 = $cartService->addLine($cart, new CartLineItemData($p1->id, null, CartQuantity::fromInt(1)));
    $line2 = $cartService->addLine($cart, new CartLineItemData($p2->id, null, CartQuantity::fromInt(1)));
    $line3 = $cartService->addLine($cart, new CartLineItemData($p3->id, null, CartQuantity::fromInt(1)));

    $checkoutOrch = app(CheckoutOrchestratorInterface::class);
    $session = $checkoutOrch->createFromCart($cart);
    $session = $checkoutOrch->setCustomerData($session, new CheckoutCustomerData('d@example.com', 'Dave', 'Tester'));
    $session = $checkoutOrch->setAddresses($session, new CheckoutAddress('Dave', ['Potsdamer Platz 1'], 'Berlin', 'DE', postalCode: '10785'));
    $session = $checkoutOrch->applyCoupon($session, '100CENTS');

    $session = $checkoutOrch->selectShippingQuote($session, ['method_id' => $shippingMethod->id, 'method_code' => $shippingMethod->code]);
    $session = $checkoutOrch->reserveInventory($session);
    $ready = $checkoutOrch->markReadyForOrder($session);

    $orderResult = $this->creationService->createFromCheckout(new OrderCreationDTO($this->tenant->id, $session->id));
    $order = $orderResult->order;

    // Floor share: 100 * 1000 / 3000 = 33 floor, 1000 remainder on each of the 3 lines.
    // Leftover: 100 - (33*3) = 1.
    // Line IDs are line1->id < line2->id < line3->id.
    // Deterministic tie-breaker: cart_line_id ascending awards the +1 to line1.
    // Result: line 1 receives 34, line 2 receives 33, line 3 receives 33. Sum = 100!
    $item1 = $order->items->firstWhere('product_id', $p1->id);
    $item2 = $order->items->firstWhere('product_id', $p2->id);
    $item3 = $order->items->firstWhere('product_id', $p3->id);

    expect($item1->allocated_cart_discount_minor)->toBe(34)
        ->and($item2->allocated_cart_discount_minor)->toBe(33)
        ->and($item3->allocated_cart_discount_minor)->toBe(33)
        ->and($order->discount_total_minor)->toBe(100)
        ->and($item1->allocated_cart_discount_minor + $item2->allocated_cart_discount_minor + $item3->allocated_cart_discount_minor)->toBe(100);
});

test('TEST F: 100% cart discount reduces taxable base to zero and tax to zero safely', function (): void {
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $taxClass = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT19_F', 'name' => '19% VAT', 'is_default' => true]);
    $tz = TaxZone::create(['tenant_id' => $this->tenant->id, 'code' => 'TZ_DE_F', 'name' => 'DE Zone F', 'country_code' => 'DE', 'priority' => 10]);
    TaxRate::create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $taxClass->id, 'tax_zone_id' => $tz->id, 'name' => '19% VAT Rate', 'rate_percentage' => '19.0000', 'priority' => 0]);

    $p = createPipelineProduct($this, 5000, $taxClass, 'PROD-F', 'prod-f');
    $shippingMethod = ensureFreeShippingMethod($this);

    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '100% Free Promo F',
        'code' => 'PROMO_FREE100_F',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 100],
    ]);
    Coupon::create(['tenant_id' => $this->tenant->id, 'promotion_id' => $promo->id, 'code' => 'FREE100F', 'status' => 'active']);

    $cartService = app(CartServiceInterface::class);
    $cart = $cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $cartService->addLine($cart, new CartLineItemData($p->id, null, CartQuantity::fromInt(1)));

    $checkoutOrch = app(CheckoutOrchestratorInterface::class);
    $session = $checkoutOrch->createFromCart($cart);
    $session = $checkoutOrch->setCustomerData($session, new CheckoutCustomerData('f@example.com', 'Frank', 'Tester'));
    $session = $checkoutOrch->setAddresses($session, new CheckoutAddress('Frank', ['Museumsinsel 1'], 'Berlin', 'DE', postalCode: '10178'));
    $session = $checkoutOrch->applyCoupon($session, 'FREE100F');

    $session = $checkoutOrch->selectShippingQuote($session, ['method_id' => $shippingMethod->id, 'method_code' => $shippingMethod->code]);
    $session = $checkoutOrch->reserveInventory($session);
    $ready = $checkoutOrch->markReadyForOrder($session);

    $orderResult = $this->creationService->createFromCheckout(new OrderCreationDTO($this->tenant->id, $session->id));
    $order = $orderResult->order;

    expect($order->merchandise_subtotal_minor)->toBe(5000)
        ->and($order->discount_total_minor)->toBe(5000)
        ->and($order->tax_total_minor)->toBe(0)
        ->and($order->grand_total_minor)->toBe(0);

    $item = $order->items->first();
    expect($item->allocated_cart_discount_minor)->toBe(5000)
        ->and($item->taxable_amount_minor)->toBe(0)
        ->and($item->tax_minor)->toBe(0)
        ->and($item->total_minor)->toBe(0);
});

test('TEST G: mixed tax classes (19% and 7%) calculate per-line tax exactly on discounted taxable base', function (): void {
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $tz = TaxZone::create(['tenant_id' => $this->tenant->id, 'code' => 'TZ_DE_G', 'name' => 'DE Zone G', 'country_code' => 'DE', 'priority' => 10]);

    $taxClass19 = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT19_G', 'name' => '19% Standard', 'is_default' => false]);
    TaxRate::create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $taxClass19->id, 'tax_zone_id' => $tz->id, 'name' => '19% VAT', 'rate_percentage' => '19.0000', 'priority' => 0]);

    $taxClass7 = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT7_G', 'name' => '7% Reduced', 'is_default' => false]);
    TaxRate::create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $taxClass7->id, 'tax_zone_id' => $tz->id, 'name' => '7% VAT', 'rate_percentage' => '7.0000', 'priority' => 0]);

    $p1 = createPipelineProduct($this, 5000, $taxClass19, 'PROD-G1', 'prod-g1');
    $p2 = createPipelineProduct($this, 5000, $taxClass7, 'PROD-G2', 'prod-g2');
    $shippingMethod = ensureFreeShippingMethod($this);

    // 2000 minor fixed discount (1000 each)
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '20 EUR Off G',
        'code' => 'PROMO_20G',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 2000, 'currency' => 'EUR'],
    ]);
    Coupon::create(['tenant_id' => $this->tenant->id, 'promotion_id' => $promo->id, 'code' => '20OFFG', 'status' => 'active']);

    $cartService = app(CartServiceInterface::class);
    $cart = $cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $cartService->addLine($cart, new CartLineItemData($p1->id, null, CartQuantity::fromInt(1)));
    $cartService->addLine($cart, new CartLineItemData($p2->id, null, CartQuantity::fromInt(1)));

    $checkoutOrch = app(CheckoutOrchestratorInterface::class);
    $session = $checkoutOrch->createFromCart($cart);
    $session = $checkoutOrch->setCustomerData($session, new CheckoutCustomerData('g@example.com', 'Grace', 'Tester'));
    $session = $checkoutOrch->setAddresses($session, new CheckoutAddress('Grace', ['Unter den Linden 1'], 'Berlin', 'DE', postalCode: '10117'));
    $session = $checkoutOrch->applyCoupon($session, '20OFFG');

    $session = $checkoutOrch->selectShippingQuote($session, ['method_id' => $shippingMethod->id, 'method_code' => $shippingMethod->code]);
    $session = $checkoutOrch->reserveInventory($session);
    $ready = $checkoutOrch->markReadyForOrder($session);

    $orderResult = $this->creationService->createFromCheckout(new OrderCreationDTO($this->tenant->id, $session->id));
    $order = $orderResult->order;

    // Line 1: 5000 - 1000 = 4000 taxable. 19% of 4000 = 760 tax. Line total = 4760.
    // Line 2: 5000 - 1000 = 4000 taxable. 7% of 4000 = 280 tax. Line total = 4280.
    // Total Tax: 760 + 280 = 1040.
    // Grand Total: 10000 - 2000 + 1040 = 9040.
    expect($order->merchandise_subtotal_minor)->toBe(10000)
        ->and($order->discount_total_minor)->toBe(2000)
        ->and($order->tax_total_minor)->toBe(1040)
        ->and($order->grand_total_minor)->toBe(9040);

    $item1 = $order->items->firstWhere('product_id', $p1->id);
    $item2 = $order->items->firstWhere('product_id', $p2->id);

    expect($item1->allocated_cart_discount_minor)->toBe(1000)
        ->and($item1->taxable_amount_minor)->toBe(4000)
        ->and($item1->tax_minor)->toBe(760)
        ->and($item1->total_minor)->toBe(4760);

    expect($item2->allocated_cart_discount_minor)->toBe(1000)
        ->and($item2->taxable_amount_minor)->toBe(4000)
        ->and($item2->tax_minor)->toBe(280)
        ->and($item2->total_minor)->toBe(4280);
});

test('TEST H: mixed taxable and real 0.0000% zero-rated lines calculate per-line tax accurately', function (): void {
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $tz = TaxZone::create(['tenant_id' => $this->tenant->id, 'code' => 'TZ_DE_H', 'name' => 'DE Zone H', 'country_code' => 'DE', 'priority' => 10]);

    $taxClass19 = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT19_H', 'name' => '19% Standard', 'is_default' => false]);
    TaxRate::create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $taxClass19->id, 'tax_zone_id' => $tz->id, 'name' => '19% VAT', 'rate_percentage' => '19.0000', 'priority' => 0]);

    // Real zero-rated tax class and explicit 0.0000% rate
    $taxClass0 = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT0_H', 'name' => '0% Zero-Rated', 'is_default' => false]);
    TaxRate::create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $taxClass0->id, 'tax_zone_id' => $tz->id, 'name' => '0.0000% VAT Rate', 'rate_percentage' => '0.0000', 'priority' => 0]);

    $p1 = createPipelineProduct($this, 5000, $taxClass19, 'PROD-H1', 'prod-h1');
    $p2 = createPipelineProduct($this, 5000, $taxClass0, 'PROD-H2', 'prod-h2');
    $shippingMethod = ensureFreeShippingMethod($this);

    // 2000 minor fixed discount
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '20 EUR Off H',
        'code' => 'PROMO_20H',
        'status' => 'active',
        'priority' => 1,
    ]);
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 2000, 'currency' => 'EUR'],
    ]);
    Coupon::create(['tenant_id' => $this->tenant->id, 'promotion_id' => $promo->id, 'code' => '20OFFH', 'status' => 'active']);

    $cartService = app(CartServiceInterface::class);
    $cart = $cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $cartService->addLine($cart, new CartLineItemData($p1->id, null, CartQuantity::fromInt(1)));
    $cartService->addLine($cart, new CartLineItemData($p2->id, null, CartQuantity::fromInt(1)));

    $checkoutOrch = app(CheckoutOrchestratorInterface::class);
    $session = $checkoutOrch->createFromCart($cart);
    $session = $checkoutOrch->setCustomerData($session, new CheckoutCustomerData('h@example.com', 'Hank', 'Tester'));
    $session = $checkoutOrch->setAddresses($session, new CheckoutAddress('Hank', ['Tiergarten 1'], 'Berlin', 'DE', postalCode: '10557'));
    $session = $checkoutOrch->applyCoupon($session, '20OFFH');

    $session = $checkoutOrch->selectShippingQuote($session, ['method_id' => $shippingMethod->id, 'method_code' => $shippingMethod->code]);
    $session = $checkoutOrch->reserveInventory($session);
    $ready = $checkoutOrch->markReadyForOrder($session);

    $orderResult = $this->creationService->createFromCheckout(new OrderCreationDTO($this->tenant->id, $session->id));
    $order = $orderResult->order;

    // Line 1: 5000 - 1000 = 4000 taxable. 19% tax = 760. Total = 4760.
    // Line 2: 5000 - 1000 = 4000 taxable. 0% tax = 0. Total = 4000.
    // Header: subtotal 10000, discount 2000, tax 760, grand total 8760.
    expect($order->merchandise_subtotal_minor)->toBe(10000)
        ->and($order->discount_total_minor)->toBe(2000)
        ->and($order->tax_total_minor)->toBe(760)
        ->and($order->grand_total_minor)->toBe(8760);

    $item1 = $order->items->firstWhere('product_id', $p1->id);
    $item2 = $order->items->firstWhere('product_id', $p2->id);

    expect($item1->allocated_cart_discount_minor)->toBe(1000)
        ->and($item1->taxable_amount_minor)->toBe(4000)
        ->and($item1->tax_minor)->toBe(760)
        ->and($item1->total_minor)->toBe(4760);

    expect($item2->allocated_cart_discount_minor)->toBe(1000)
        ->and($item2->taxable_amount_minor)->toBe(4000)
        ->and($item2->tax_minor)->toBe(0)
        ->and($item2->total_minor)->toBe(4000);
});

test('TEST I, J, K, L: multiple discounts allocate deterministically and Order consumes immutable values', function (): void {
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true]);

    $tz = TaxZone::create(['tenant_id' => $this->tenant->id, 'code' => 'TZ_DE_I', 'name' => 'DE Zone I', 'country_code' => 'DE', 'priority' => 10]);
    $taxClass = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'VAT10_I', 'name' => '10% VAT', 'is_default' => true]);
    TaxRate::create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $taxClass->id, 'tax_zone_id' => $tz->id, 'name' => '10% VAT', 'rate_percentage' => '10.0000', 'priority' => 0]);

    $p1 = createPipelineProduct($this, 6000, $taxClass, 'PROD-I1', 'prod-i1');
    $p2 = createPipelineProduct($this, 4000, $taxClass, 'PROD-I2', 'prod-i2');
    $shippingMethod = ensureFreeShippingMethod($this);

    // Two active stacking promotions:
    // Promo 1 (priority 2): Fixed 1000 discount (600 to p1, 400 to p2). Remaining: 5400, 3600.
    // Promo 2 (priority 1): 10% percentage discount on remaining cart total (900 total: 540 to p1, 360 to p2).
    // Total discount = 1000 + 900 = 1900.
    $promo1 = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Fixed 1000 Promo',
        'code' => 'PROMO_FIXED_1000',
        'priority' => 2,
        'is_stackable' => true,
        'status' => 'active',
    ]);
    PromotionAction::create([
        'promotion_id' => $promo1->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 1000, 'currency' => 'EUR'],
    ]);

    $promo2 = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Percent 10 Promo',
        'code' => 'PROMO_PERCENT_10',
        'priority' => 1,
        'is_stackable' => true,
        'status' => 'active',
    ]);
    PromotionAction::create([
        'promotion_id' => $promo2->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 10],
    ]);

    $cartService = app(CartServiceInterface::class);
    $cart = $cartService->getOrCreateActiveCart(new CartContext($this->tenant->id, $this->store->id, $this->market->id, $this->channel->id, 'EUR', 'en', $this->user->id));
    $cartService->addLine($cart, new CartLineItemData($p1->id, null, CartQuantity::fromInt(1)));
    $cartService->addLine($cart, new CartLineItemData($p2->id, null, CartQuantity::fromInt(1)));

    $checkoutOrch = app(CheckoutOrchestratorInterface::class);
    $session = $checkoutOrch->createFromCart($cart);
    $session = $checkoutOrch->setCustomerData($session, new CheckoutCustomerData('i@example.com', 'Iris', 'Tester'));
    $session = $checkoutOrch->setAddresses($session, new CheckoutAddress('Iris', ['Gendarmenmarkt 1'], 'Berlin', 'DE', postalCode: '10117'));

    $session = $checkoutOrch->selectShippingQuote($session, ['method_id' => $shippingMethod->id, 'method_code' => $shippingMethod->code]);
    $session = $checkoutOrch->reserveInventory($session);
    $ready = $checkoutOrch->markReadyForOrder($session);

    $orderResult = $this->creationService->createFromCheckout(new OrderCreationDTO($this->tenant->id, $session->id));
    $order = $orderResult->order;

    // Subtotal: 10000
    // Total discount: 1900 (p1: 600 + 540 = 1140; p2: 400 + 360 = 760)
    // Taxable base: p1 = 6000 - 1140 = 4860; p2 = 4000 - 760 = 3240. Total taxable = 8100.
    // Taxes (10%): p1 = 486; p2 = 324. Total tax = 810.
    // Grand Total: 10000 - 1900 + 810 = 8910.
    expect($order->merchandise_subtotal_minor)->toBe(10000)
        ->and($order->discount_total_minor)->toBe(1900)
        ->and($order->tax_total_minor)->toBe(810)
        ->and($order->grand_total_minor)->toBe(8910);

    $item1 = $order->items->firstWhere('product_id', $p1->id);
    $item2 = $order->items->firstWhere('product_id', $p2->id);

    expect($item1->allocated_cart_discount_minor)->toBe(1140)
        ->and($item1->taxable_amount_minor)->toBe(4860)
        ->and($item1->tax_minor)->toBe(486)
        ->and($item1->total_minor)->toBe(4860 + 486);

    expect($item2->allocated_cart_discount_minor)->toBe(760)
        ->and($item2->taxable_amount_minor)->toBe(3240)
        ->and($item2->tax_minor)->toBe(324)
        ->and($item2->total_minor)->toBe(3240 + 324);

    // Assert J: sum allocated discounts exactly equals header cart_discounts
    expect($item1->allocated_cart_discount_minor + $item2->allocated_cart_discount_minor)->toBe($order->discount_total_minor);

    // Assert K: sum line tax exactly equals header tax_total
    expect($item1->tax_minor + $item2->tax_minor)->toBe($order->tax_total_minor);

    // Assert L: Order receives exactly the immutable Checkout values
    $pSnapshotLines = $ready->pricingSnapshot['lines'];
    expect($item1->subtotal_minor)->toBe($pSnapshotLines[0]['merchandise_line_subtotal_minor'])
        ->and($item1->allocated_cart_discount_minor)->toBe($pSnapshotLines[0]['allocated_cart_discount_minor'])
        ->and($item1->taxable_amount_minor)->toBe($pSnapshotLines[0]['taxable_amount_minor'])
        ->and($item1->tax_minor)->toBe($pSnapshotLines[0]['tax_minor'])
        ->and($item1->total_minor)->toBe($pSnapshotLines[0]['line_total_minor']);
});
