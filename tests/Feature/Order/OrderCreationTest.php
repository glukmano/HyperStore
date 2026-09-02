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
                    'line_total_minor' => 5000,
                    'line_discount_minor' => 0,
                    'tax_minor' => 500,
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
        ->and($result->order->checkout_id)->toBe($checkout->id);

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
            'quantity' => '1.00000000',
        ],
        [
            'cart_line_id' => 202,
            'product_id' => $this->product->id,
            'variant_id' => $v2->id,
            'sku_snapshot' => 'VAR-2',
            'name_snapshot' => 'Variant 2 Item',
            'product_type_snapshot' => 'physical',
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
            'line_total_minor' => 1000,
            'line_discount_minor' => 0,
            'tax_minor' => 0,
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
            'line_total_minor' => 3000,
            'line_discount_minor' => 0,
            'tax_minor' => 0,
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
            'line_total_minor' => 2000,
            'line_discount_minor' => 0,
            'tax_minor' => 0,
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
            'line_total_minor' => 2500,
            'line_discount_minor' => 0,
            'tax_minor' => 0,
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
        'line_total_minor' => 500,
        'line_discount_minor' => 0,
        'tax_minor' => 0,
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

    $snapshot['pricing_snapshot']['lines'][0]['tax_minor'] = 0;
    $snapshot['pricing_snapshot']['lines'][0]['line_total_minor'] = 4500;

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
