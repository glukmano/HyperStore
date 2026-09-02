<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\Contracts\OrderOwnershipServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Enums\OrderActorType;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Exceptions\OrderAccessDeniedException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderOperationKey;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
    $this->tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

    $this->marketA = Market::create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Market A',
        'code' => 'MKT-A',
        'is_active' => true,
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'en',
        'timezone' => 'Europe/Zurich',
    ]);
    $this->storeA = Store::create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Store A',
        'slug' => 'store-a',
        'status' => 'active',
    ]);
    $this->channelA = Channel::create([
        'type' => 'website',
        'name' => 'Web A',
        'handle' => 'web-a-'.uniqid(),
        'is_active' => true,
    ]);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenantA->id,
        productType: 'physical',
        sku: 'SEC-SKU-001',
        translations: ['en' => ['name' => 'Security Product']],
    ));

    $this->user1 = User::factory()->create();
    $this->user2 = User::factory()->create();

    $this->ownershipService = app(OrderOwnershipServiceInterface::class);
    $this->creationService = app(OrderCreationServiceInterface::class);
});

function createOrderHelper($test, ?int $userId = null): array
{
    $cart = Cart::create([
        'tenant_id' => $test->tenantA->id,
        'user_id' => $userId,
        'store_id' => $test->storeA->id,
        'market_id' => $test->marketA->id,
        'channel_id' => $test->channelA->id,
        'currency' => 'EUR',
        'locale' => 'en',
        'status' => 'active',
    ]);

    $checkout = CheckoutSession::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $test->tenantA->id,
        'cart_id' => $cart->id,
        'user_id' => $userId,
        'guest_token_hash' => $userId === null ? hash('sha256', 'cart-checkout-token-xyz') : null,
        'store_id' => $test->storeA->id,
        'market_id' => $test->marketA->id,
        'channel_id' => $test->channelA->id,
        'currency' => 'EUR',
        'locale' => 'en',
        'state' => 'ready_for_order',
        'customer_data' => ['email' => 'sec@example.com'],
        'shipping_address' => ['country_code' => 'CH'],
        'billing_address' => ['country_code' => 'CH'],
        'ready_snapshot' => [
            'context' => [
                'store_id' => $test->storeA->id,
                'market_id' => $test->marketA->id,
                'channel_id' => $test->channelA->id,
                'currency' => 'EUR',
                'locale' => 'en',
            ],
            'totals' => [
                'merchandise_subtotal' => 1000,
                'line_discounts' => 0,
                'cart_discounts' => 0,
                'shipping_original' => 0,
                'shipping_discount' => 0,
                'shipping_final' => 0,
                'tax_total' => 0,
                'grand_total' => 1000,
                'currency' => 'EUR',
            ],
            'lines' => [[
                'cart_line_id' => 5001,
                'product_id' => $test->product->id,
                'sku_snapshot' => 'SEC-SKU-001',
                'name_snapshot' => 'Security Product',
                'product_type_snapshot' => 'physical',
                'quantity' => '1.00000000',
            ]],
            'pricing_snapshot' => [
                'lines' => [[
                    'cart_line_id' => 5001,
                    'product_id' => $test->product->id,
                    'variant_id' => null,
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
                ]],
                'subtotal_minor' => 1000,
                'currency' => 'EUR',
            ],
            'customer_data' => ['email' => 'sec@example.com'],
            'reservation_references' => [],
        ],
        'evaluated_cart_version' => 1,
        'version' => 1,
        'expires_at' => now()->addHour(),
    ]);

    $result = $test->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $test->tenantA->id,
        checkoutId: $checkout->id,
        actorType: $userId !== null ? OrderActorType::CUSTOMER : OrderActorType::GUEST,
        actorId: $userId,
    ));

    return [$result->order, $result->guestAccessToken, $checkout];
}

// ---------------------------------------------------------------------------
// 1. Fresh guest token generated, SHA-256 hash stored, plaintext excluded from OrderCreated
// ---------------------------------------------------------------------------
test('guest order creation generates fresh token and never exposes plaintext to OrderCreated event or DB', function (): void {
    Event::fake([OrderCreated::class]);

    [$order, $plainToken, $checkout] = createOrderHelper($this, userId: null);

    expect($plainToken)->not->toBeNull()
        ->and(strlen($plainToken))->toBe(64)
        ->and($order->guest_token_hash)->toBe(hash('sha256', $plainToken))
        ->and($order->guest_token_hash)->not->toBe($plainToken);

    // Assert raw DB contains only hash
    $raw = DB::selectOne('SELECT guest_token_hash FROM orders WHERE id = ?', [$order->id]);
    expect($raw->guest_token_hash)->toBe(hash('sha256', $plainToken));

    // Assert OrderCreated event has NO guestAccessToken property
    Event::assertDispatched(OrderCreated::class, function (OrderCreated $event) use ($plainToken) {
        $serialized = serialize($event);
        expect(property_exists($event, 'guestAccessToken'))->toBeFalse()
            ->and(str_contains($serialized, $plainToken))->toBeFalse();

        return true;
    });

    // Assert order_operation_keys never contains plaintext token
    $opKeys = OrderOperationKey::where('tenant_id', $this->tenantA->id)->get();
    foreach ($opKeys as $k) {
        $json = json_encode($k->response_payload);
        expect(str_contains((string) $json, $plainToken))->toBeFalse();
    }
});

// ---------------------------------------------------------------------------
// 2. Replay does not return plaintext guest token
// ---------------------------------------------------------------------------
test('idempotency replay returns same order without plaintext guest token', function (): void {
    $cart = Cart::create([
        'tenant_id' => $this->tenantA->id,
        'user_id' => null,
        'store_id' => $this->storeA->id,
        'market_id' => $this->marketA->id,
        'channel_id' => $this->channelA->id,
        'currency' => 'EUR',
        'locale' => 'en',
        'status' => 'active',
    ]);

    $checkout = CheckoutSession::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenantA->id,
        'cart_id' => $cart->id,
        'user_id' => null,
        'guest_token_hash' => hash('sha256', 'guest-chk'),
        'store_id' => $this->storeA->id,
        'market_id' => $this->marketA->id,
        'channel_id' => $this->channelA->id,
        'currency' => 'EUR',
        'locale' => 'en',
        'state' => 'ready_for_order',
        'ready_snapshot' => [
            'context' => [
                'store_id' => $this->storeA->id,
                'market_id' => $this->marketA->id,
                'channel_id' => $this->channelA->id,
                'currency' => 'EUR',
                'locale' => 'en',
            ],
            'totals' => [
                'merchandise_subtotal' => 1000,
                'line_discounts' => 0,
                'cart_discounts' => 0,
                'shipping_original' => 0,
                'shipping_discount' => 0,
                'shipping_final' => 0,
                'tax_total' => 0,
                'grand_total' => 1000,
                'currency' => 'EUR',
            ],
            'lines' => [[
                'cart_line_id' => 5002,
                'product_id' => $this->product->id,
                'sku_snapshot' => 'SEC-SKU-001',
                'name_snapshot' => 'Security Product',
                'product_type_snapshot' => 'physical',
                'quantity' => '1.00000000',
            ]],
            'pricing_snapshot' => [
                'lines' => [[
                    'cart_line_id' => 5002,
                    'product_id' => $this->product->id,
                    'variant_id' => null,
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
                ]],
                'subtotal_minor' => 1000,
                'currency' => 'EUR',
            ],
            'customer_data' => ['email' => 'sec@example.com'],
            'reservation_references' => [],
        ],
        'evaluated_cart_version' => 1,
        'version' => 1,
        'expires_at' => now()->addHour(),
    ]);

    $first = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenantA->id,
        checkoutId: $checkout->id,
    ));

    $second = $this->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $this->tenantA->id,
        checkoutId: $checkout->id,
    ));

    expect($first->guestAccessToken)->not->toBeNull()
        ->and($second->guestAccessToken)->toBeNull()
        ->and($second->isReplay)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 3. Cart/Checkout guest token cannot access Order
// ---------------------------------------------------------------------------
test('cart or checkout tokens cannot access guest order', function (): void {
    [$order, $plainToken, $checkout] = createOrderHelper($this, userId: null);

    expect(fn () => $this->ownershipService->verifyOwnership($order, 'cart-checkout-token-xyz'))
        ->toThrow(OrderAccessDeniedException::class);
});

// ---------------------------------------------------------------------------
// 4. Correct guest token accepts, wrong token rejects
// ---------------------------------------------------------------------------
test('correct guest token succeeds and wrong token throws OrderAccessDeniedException', function (): void {
    [$order, $plainToken] = createOrderHelper($this, userId: null);

    $this->ownershipService->verifyOwnership($order, $plainToken);

    expect(fn () => $this->ownershipService->verifyOwnership($order, 'wrong-token-abc'))
        ->toThrow(OrderAccessDeniedException::class);

    expect(fn () => $this->ownershipService->verifyOwnership($order, null))
        ->toThrow(OrderAccessDeniedException::class);
});

// ---------------------------------------------------------------------------
// 5. Authenticated customer ownership and same-tenant IDOR protection
// ---------------------------------------------------------------------------
test('authenticated user can access own order but another same-tenant user is denied', function (): void {
    [$order] = createOrderHelper($this, userId: $this->user1->id);

    $this->actingAs($this->user1);
    $this->ownershipService->verifyOwnership($order);

    $this->actingAs($this->user2);
    expect(fn () => $this->ownershipService->verifyOwnership($order))
        ->toThrow(OrderAccessDeniedException::class);
});

// ---------------------------------------------------------------------------
// 6. Staff with RBAC order.view can access customer order
// ---------------------------------------------------------------------------
test('staff with order.view permission can access customer order', function (): void {
    Permission::findOrCreate('order.view');

    $staff = User::factory()->create();
    $staff->givePermissionTo('order.view');

    [$order] = createOrderHelper($this, userId: $this->user1->id);

    $this->actingAs($staff);
    $this->ownershipService->verifyOwnership($order);
    expect(true)->toBeTrue();
});
