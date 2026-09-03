<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Core\Channels\Models\Channel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Context\DTOs\UserContext;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Checkout\Models\CheckoutSession;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['name' => 'API Tenant', 'slug' => 'api-tenant', 'status' => 'active']);
    $this->market = Market::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'API Market',
        'code' => 'API-MKT',
        'is_active' => true,
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'en',
        'timezone' => 'Europe/Berlin',
    ]);
    $this->store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'API Store',
        'slug' => 'api-store',
        'status' => 'active',
    ]);
    $this->channel = Channel::create([
        'type' => 'website',
        'name' => 'API Channel',
        'handle' => 'api-web-'.uniqid(),
        'is_active' => true,
    ]);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'API-SKU-001',
        translations: ['en' => ['name' => 'API Product']],
    ));

    $this->customer = User::factory()->create();

    $this->contextManager = app(ContextManager::class);
    $this->contextManager->setTenant(TenantContext::from($this->tenant->id));
});

function makeReadyCheckoutForApi($test, ?int $userId = null, ?string $guestToken = null): CheckoutSession
{
    $cart = Cart::create([
        'tenant_id' => $test->tenant->id,
        'user_id' => $userId,
        'guest_token_hash' => $guestToken !== null ? hash('sha256', $guestToken) : null,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => 'en',
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'status' => 'active',
    ]);

    return CheckoutSession::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $test->tenant->id,
        'cart_id' => $cart->id,
        'user_id' => $userId,
        'guest_token_hash' => $guestToken !== null ? hash('sha256', $guestToken) : null,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => 'en',
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'state' => 'ready_for_order',
        'customer_data' => [
            'email' => 'api@example.com',
            'first_name' => 'API',
            'last_name' => 'User',
        ],
        'shipping_address' => [
            'first_name' => 'API',
            'last_name' => 'User',
            'country_code' => 'DE',
        ],
        'billing_address' => [
            'first_name' => 'API',
            'last_name' => 'User',
            'country_code' => 'DE',
        ],
        'ready_snapshot' => [
            'context' => [
                'store_id' => $test->store->id,
                'market_id' => $test->market->id,
                'channel_id' => $test->channel->id,
                'currency' => 'EUR',
                'locale' => 'en',
                'commercial_model_snapshot' => 'platform_as_merchant_of_record',
            ],
            'totals' => [
                'merchandise_subtotal' => 3000,
                'line_discounts' => 0,
                'cart_discounts' => 0,
                'shipping_original' => 0,
                'shipping_discount' => 0,
                'shipping_final' => 0,
                'tax_total' => 300,
                'grand_total' => 3300,
                'currency' => 'EUR',
            ],
            'lines' => [[
                'cart_line_id' => 6001,
                'product_id' => $test->product->id,
                'sku_snapshot' => 'API-SKU-001',
                'name_snapshot' => 'API Product',
                'product_type_snapshot' => 'physical',
                'requires_shipping_snapshot' => true,
                'quantity' => '1.00000000',
            ]],
            'pricing_snapshot' => [
                'lines' => [[
                    'cart_line_id' => 6001,
                    'product_id' => $test->product->id,
                    'variant_id' => null,
                    'quantity' => '1.00000000',
                    'unit_price_minor' => 3000,
                    'merchandise_line_subtotal_minor' => 3000,
                    'line_discount_minor' => 0,
                    'allocated_cart_discount_minor' => 0,
                    'taxable_amount_minor' => 3000,
                    'tax_minor' => 300,
                    'line_total_minor' => 3300,
                    'tax_class_id' => null,
                    'tax_rate_percent' => null,
                    'currency' => 'EUR',
                ]],
                'subtotal_minor' => 3000,
                'currency' => 'EUR',
            ],
            'customer_data' => ['email' => 'api@example.com', 'first_name' => 'API', 'last_name' => 'User'],
            'reservation_references' => [],
        ],
        'evaluated_cart_version' => 1,
        'version' => 1,
        'expires_at' => now()->addHour(),
    ]);
}

// ---------------------------------------------------------------------------
// 1. Customer creates order via API
// ---------------------------------------------------------------------------
test('customer can create order from ready checkout via POST /api/v1/orders', function (): void {
    $checkout = makeReadyCheckoutForApi($this, userId: $this->customer->id);

    $this->contextManager->setUser(UserContext::from($this->customer->id, $this->customer->email));
    Sanctum::actingAs($this->customer, ['*']);

    $response = $this->postJson('/api/v1/orders', [
        'checkout_id' => $checkout->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('order.order_status', 'placed')
        ->assertJsonPath('order.totals.grand_total_minor', 3300)
        ->assertJsonPath('is_replay', false);
});

// ---------------------------------------------------------------------------
// 2. Guest creates order and receives guest access token
// ---------------------------------------------------------------------------
test('guest creates order and receives plaintext guest_access_token only on first creation', function (): void {
    $guestToken = 'guest-chk-token-123';
    $checkout = makeReadyCheckoutForApi($this, userId: null, guestToken: $guestToken);

    // 1st request: created (201) with guest_access_token
    $res1 = $this->withHeader('X-Guest-Token', $guestToken)
        ->withHeader('Idempotency-Key', 'idem-guest-01')
        ->postJson('/api/v1/orders', [
            'checkout_id' => $checkout->id,
        ]);

    $res1->assertStatus(201)
        ->assertJsonStructure(['order', 'guest_access_token', 'is_replay'])
        ->assertJsonPath('is_replay', false);

    $orderGuestToken = $res1->json('guest_access_token');
    expect($orderGuestToken)->not->toBeNull();

    // 2nd request (replay): 200 without guest_access_token
    $res2 = $this->withHeader('X-Guest-Token', $guestToken)
        ->withHeader('Idempotency-Key', 'idem-guest-01')
        ->postJson('/api/v1/orders', [
            'checkout_id' => $checkout->id,
        ]);

    $res2->assertStatus(200)
        ->assertJsonPath('is_replay', true)
        ->assertJsonMissing(['guest_access_token']);
});

// ---------------------------------------------------------------------------
// 3. Retrieve order via GET /api/v1/orders/{identifier}
// ---------------------------------------------------------------------------
test('customer can retrieve own order and guest can retrieve with order token', function (): void {
    $checkout = makeReadyCheckoutForApi($this, userId: $this->customer->id);

    $this->contextManager->setUser(UserContext::from($this->customer->id, $this->customer->email));
    Sanctum::actingAs($this->customer, ['*']);

    $created = $this->postJson('/api/v1/orders', ['checkout_id' => $checkout->id])
        ->json('order');

    $orderId = $created['id'];
    $orderUuid = $created['uuid'];
    $orderNumber = $created['order_number'];

    // By ID
    $this->getJson("/api/v1/orders/{$orderId}")
        ->assertStatus(200)
        ->assertJsonPath('data.order_number', $orderNumber);

    // By UUID
    $this->getJson("/api/v1/orders/{$orderUuid}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $orderId);

    // By Order Number
    $this->getJson("/api/v1/orders/{$orderNumber}")
        ->assertStatus(200)
        ->assertJsonPath('data.uuid', $orderUuid);
});

// ---------------------------------------------------------------------------
// 4. Cancel order via POST /api/v1/orders/{identifier}/cancel
// ---------------------------------------------------------------------------
test('customer can cancel placed order via API', function (): void {
    $checkout = makeReadyCheckoutForApi($this, userId: $this->customer->id);

    $this->contextManager->setUser(UserContext::from($this->customer->id, $this->customer->email));
    Sanctum::actingAs($this->customer, ['*']);

    $order = $this->postJson('/api/v1/orders', ['checkout_id' => $checkout->id])
        ->json('order');

    $this->postJson("/api/v1/orders/{$order['id']}/cancel", [
        'reason' => 'Ordered wrong size',
    ])
        ->assertStatus(200)
        ->assertJsonPath('order.order_status', 'cancelled')
        ->assertJsonPath('order.fulfillment_status', 'cancelled');
});

// ---------------------------------------------------------------------------
// 5. Control center endpoints with RBAC
// ---------------------------------------------------------------------------
test('control center operator can list, view diagnostic, transition and cancel orders', function (): void {
    Permission::findOrCreate('order.view');
    Permission::findOrCreate('order.manage');
    Permission::findOrCreate('order.cancel');

    $staff = User::factory()->create();
    $staff->givePermissionTo(['order.view', 'order.manage', 'order.cancel']);

    $checkout = makeReadyCheckoutForApi($this, userId: $this->customer->id);

    $this->contextManager->setUser(UserContext::from($this->customer->id, $this->customer->email));
    Sanctum::actingAs($this->customer, ['*']);

    $orderData = $this->postJson('/api/v1/orders', ['checkout_id' => $checkout->id])
        ->json('order');
    $orderId = $orderData['id'];

    // Switch to staff context
    $this->contextManager->setUser(UserContext::from($staff->id, $staff->email));
    Sanctum::actingAs($staff, ['*']);

    // 1. List
    $this->getJson('/api/v1/control-center/orders')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta']);

    // 2. Diagnostic detail (masked PII)
    $this->getJson("/api/v1/control-center/orders/{$orderId}")
        ->assertStatus(200)
        ->assertJsonPath('data.customer_snippet.masked_email', 'a***@example.com');

    // 3. Transition: placed -> confirmed
    $this->postJson("/api/v1/control-center/orders/{$orderId}/transition", [
        'dimension' => 'order',
        'from_status' => 'placed',
        'to_status' => 'confirmed',
        'reason' => 'Payment authorized',
    ])
        ->assertStatus(200)
        ->assertJsonPath('order.order_status', 'confirmed');

    // 4. Cancel
    $this->postJson("/api/v1/control-center/orders/{$orderId}/cancel", [
        'reason' => 'Fraud suspect',
    ])
        ->assertStatus(200)
        ->assertJsonPath('order.order_status', 'cancelled');
});
