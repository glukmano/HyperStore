<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Enums\OrderActorType;
use Modules\Order\Exceptions\CheckoutReadySnapshotMissingException;
use Modules\Order\Models\Order;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create([
        'name' => 'Snapshot Tenant',
        'slug' => 'snap-tenant',
        'is_active' => true,
    ]);

    $this->store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Snapshot Store',
        'slug' => 'snap-store',
        'status' => 'active',
        'url' => 'https://snap.example.com',
        'settings' => [
            'marketplace' => [
                'commercial_model' => 'marketplace_agent',
            ],
        ],
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
        'handle' => 'web-snap',
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'tenant_id' => $this->tenant->id,
        'sku' => 'SNAP-PROD-001',
        'name' => 'Snap Product',
        'slug' => 'snap-product',
        'product_type' => 'physical',
        'is_active' => true,
    ]);

    $this->variant = ProductVariant::create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->product->id,
        'sku' => 'SNAP-VAR-001',
        'combination_hash' => 'hash-snap-001',
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->orderCreationService = app(OrderCreationServiceInterface::class);
});

function createTestCheckoutWithSnapshot(
    $test,
    string $commercialModel,
    bool $requiresShipping,
    bool $omitCommercialModel = false,
    bool $omitRequiresShipping = false
): CheckoutSession {
    $uuid = (string) Str::uuid();

    $cart = Cart::create([
        'tenant_id' => $test->tenant->id,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'commercial_model_snapshot' => $commercialModel,
        'status' => 'active',
    ]);

    $context = [
        'tenant_id' => $test->tenant->id,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => 'de',
    ];

    if (! $omitCommercialModel) {
        $context['commercial_model_snapshot'] = $commercialModel;
    }

    $line = [
        'cart_line_id' => 101,
        'product_id' => $test->product->id,
        'variant_id' => $test->variant->id,
        'sku_snapshot' => $test->variant->sku,
        'name_snapshot' => $test->product->name,
        'product_type_snapshot' => 'physical',
        'quantity' => '1.00000000',
    ];

    if (! $omitRequiresShipping) {
        $line['requires_shipping_snapshot'] = $requiresShipping;
    }

    $readySnapshot = [
        'checkout_session_id' => 1,
        'checkout_uuid' => $uuid,
        'tenant_id' => $test->tenant->id,
        'cart_id' => $cart->id,
        'cart_version' => 1,
        'context' => $context,
        'customer_data' => [
            'email' => 'snap@example.com',
            'first_name' => 'Hans',
            'last_name' => 'Schmidt',
        ],
        'lines' => [$line],
        'totals' => [
            'merchandise_subtotal' => 5000,
            'line_discounts' => 0,
            'cart_discounts' => 0,
            'shipping_original' => 0,
            'shipping_discount' => 0,
            'shipping_final' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'currency' => 'EUR',
        ],
        'pricing_snapshot' => [
            'currency' => 'EUR',
            'lines' => [
                [
                    'cart_line_id' => 101,
                    'product_id' => $test->product->id,
                    'variant_id' => $test->variant->id,
                    'unit_price_minor' => 5000,
                    'merchandise_line_subtotal_minor' => 5000,
                    'line_discount_minor' => 0,
                    'allocated_cart_discount_minor' => 0,
                    'taxable_amount_minor' => 5000,
                    'tax_minor' => 0,
                    'line_total_minor' => 5000,
                    'currency' => 'EUR',
                    'quantity' => '1.00000000',
                    'tax_class_id' => null,
                    'tax_rate_percent' => null,
                ],
            ],
            'totals' => [
                'merchandise_subtotal_minor' => 5000,
                'line_discounts_minor' => 0,
                'cart_discounts_minor' => 0,
                'discount_total_minor' => 0,
                'tax_total_minor' => 0,
                'shipping_subtotal_minor' => 0,
                'shipping_discount_minor' => 0,
                'shipping_total_minor' => 0,
                'grand_total_minor' => 5000,
            ],
        ],
        'shipping_address' => [
            'address_line_1' => 'Teststr 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country_code' => 'DE',
        ],
        'billing_address' => [
            'address_line_1' => 'Teststr 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country_code' => 'DE',
        ],
    ];

    return CheckoutSession::create([
        'uuid' => $uuid,
        'tenant_id' => $test->tenant->id,
        'cart_id' => $cart->id,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'state' => 'ready_for_order',
        'ready_snapshot' => $readySnapshot,
    ]);
}

test('Test A: checkout ready under marketplace_agent keeps frozen commercial model after store settings mutate', function (): void {
    $checkout = createTestCheckoutWithSnapshot(
        $this,
        commercialModel: 'marketplace_agent',
        requiresShipping: true
    );

    // Mutate live Store setting to platform_as_merchant_of_record
    $this->store->update([
        'settings' => [
            'marketplace' => [
                'commercial_model' => 'platform_as_merchant_of_record',
            ],
        ],
    ]);

    // Create Order
    $result = $this->orderCreationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
        idempotencyKey: 'idem-snap-a',
        actorType: OrderActorType::CUSTOMER,
        actorId: null
    ));

    $order = $result->order;

    // Order MUST preserve frozen checkout commercial model snapshot ('marketplace_agent')
    expect($order->commercial_model_snapshot)->toBe('marketplace_agent')
        ->and($order->commercial_model_snapshot)->not->toBe('platform_as_merchant_of_record');
});

test('Test B: checkout ready requires_shipping=true keeps frozen snapshot after catalog behavior mutates to false', function (): void {
    $checkout = createTestCheckoutWithSnapshot(
        $this,
        commercialModel: 'marketplace_agent',
        requiresShipping: true
    );

    // Mutate catalog product_type to digital (which normally doesn't require shipping)
    $this->product->update(['product_type' => 'digital']);

    $result = $this->orderCreationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
        idempotencyKey: 'idem-snap-b',
        actorType: OrderActorType::CUSTOMER,
        actorId: null
    ));

    $orderItem = $result->order->items->first();

    // Order item MUST preserve frozen requires_shipping_snapshot (true)
    expect($orderItem->requires_shipping_snapshot)->toBeTrue();
});

test('Test C: checkout ready requires_shipping=false keeps frozen snapshot after catalog behavior mutates to true', function (): void {
    $checkout = createTestCheckoutWithSnapshot(
        $this,
        commercialModel: 'marketplace_agent',
        requiresShipping: false
    );

    // Mutate catalog product_type to physical
    $this->product->update(['product_type' => 'physical']);

    $result = $this->orderCreationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
        idempotencyKey: 'idem-snap-c',
        actorType: OrderActorType::CUSTOMER,
        actorId: null
    ));

    $orderItem = $result->order->items->first();

    // Order item MUST preserve frozen requires_shipping_snapshot (false)
    expect($orderItem->requires_shipping_snapshot)->toBeFalse();
});

test('Test D: missing commercial_model READY snapshot fails closed', function (): void {
    $checkout = createTestCheckoutWithSnapshot(
        $this,
        commercialModel: 'marketplace_agent',
        requiresShipping: true,
        omitCommercialModel: true
    );

    $this->expectException(CheckoutReadySnapshotMissingException::class);
    $this->expectExceptionMessage('commercial_model_snapshot');

    $this->orderCreationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
        idempotencyKey: 'idem-snap-d',
        actorType: OrderActorType::CUSTOMER,
        actorId: null
    ));
});

test('Test E: missing requires_shipping READY line truth fails closed', function (): void {
    $checkout = createTestCheckoutWithSnapshot(
        $this,
        commercialModel: 'marketplace_agent',
        requiresShipping: true,
        omitRequiresShipping: true
    );

    $this->expectException(CheckoutReadySnapshotMissingException::class);
    $this->expectExceptionMessage('requires_shipping_snapshot');

    $this->orderCreationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenant->id,
        checkoutId: $checkout->id,
        idempotencyKey: 'idem-snap-e',
        actorType: OrderActorType::CUSTOMER,
        actorId: null
    ));
});
