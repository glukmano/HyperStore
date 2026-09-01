<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutOperationKey;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Checkout\Services\CheckoutIdempotencyService;
use RuntimeException;
use Tests\TestCase;

class CheckoutOperationKeyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Cart $cart;

    private CheckoutSession $checkoutA;

    private CheckoutSession $checkoutB;

    private CheckoutIdempotencyService $idempotencyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'OpKey Tenant', 'slug' => 'op-tenant', 'status' => 'active']);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'S1', 'name' => 'Store 1', 'slug' => 's1', 'status' => 'active']);
        $market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $channel = Channel::create(['name' => 'Web', 'handle' => 'web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $store->id, 'channel_id' => $channel->id, 'is_active' => true]);

        $user = User::factory()->create();

        $this->cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => 'CHF',
            'status' => 'active',
        ]);

        $cartB = Cart::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => null,
            'guest_token_hash' => hash('sha256', 'guest-cart-b'),
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => 'CHF',
            'status' => 'active',
        ]);

        $this->checkoutA = CheckoutSession::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $this->cart->id,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => 'CHF',
            'state' => 'created',
        ]);

        $this->checkoutB = CheckoutSession::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cartB->id,
            'user_id' => null,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => 'CHF',
            'state' => 'created',
        ]);

        $this->idempotencyService = app(CheckoutIdempotencyService::class);
    }

    public function test_create_checkout_cart_scope_accepted(): void
    {
        $res = $this->idempotencyService->execute(
            tenantId: $this->tenant->id,
            cartId: $this->cart->id,
            checkoutSessionId: null,
            operationType: 'create_checkout',
            idempotencyKey: 'idemp-cart-1',
            requestPayload: ['test' => 1],
            callback: fn () => ['status' => 'ok']
        );

        $this->assertSame(['status' => 'ok'], $res);
        $this->assertDatabaseHas('checkout_operation_keys', [
            'tenant_id' => $this->tenant->id,
            'cart_id' => $this->cart->id,
            'checkout_session_id' => null,
            'operation_type' => 'create_checkout',
            'idempotency_key' => 'idemp-cart-1',
        ]);
    }

    public function test_checkout_mutation_checkout_scope_accepted(): void
    {
        $res = $this->idempotencyService->execute(
            tenantId: $this->tenant->id,
            cartId: null,
            checkoutSessionId: $this->checkoutA->id,
            operationType: 'reserve',
            idempotencyKey: 'idemp-check-1',
            requestPayload: ['test' => 2],
            callback: fn () => ['reserved' => true]
        );

        $this->assertSame(['reserved' => true], $res);
        $this->assertDatabaseHas('checkout_operation_keys', [
            'tenant_id' => $this->tenant->id,
            'cart_id' => null,
            'checkout_session_id' => $this->checkoutA->id,
            'operation_type' => 'reserve',
            'idempotency_key' => 'idemp-check-1',
        ]);
    }

    public function test_both_scope_ids_populated_rejected_by_db_check_constraint(): void
    {
        $this->expectException(QueryException::class);

        CheckoutOperationKey::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $this->cart->id,
            'checkout_session_id' => $this->checkoutA->id,
            'operation_type' => 'test',
            'idempotency_key' => 'k-both',
            'request_fingerprint' => 'fp',
            'status' => 'completed',
        ]);
    }

    public function test_both_scope_ids_null_rejected_by_db_check_constraint(): void
    {
        $this->expectException(QueryException::class);

        CheckoutOperationKey::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => null,
            'checkout_session_id' => null,
            'operation_type' => 'test',
            'idempotency_key' => 'k-none',
            'request_fingerprint' => 'fp',
            'status' => 'completed',
        ]);
    }

    public function test_same_key_on_checkout_a_and_checkout_b_are_independent(): void
    {
        $resA = $this->idempotencyService->execute(
            tenantId: $this->tenant->id,
            cartId: null,
            checkoutSessionId: $this->checkoutA->id,
            operationType: 'reserve',
            idempotencyKey: 'shared-key-1',
            requestPayload: ['checkout' => 'A'],
            callback: fn () => ['session' => 'A']
        );

        $resB = $this->idempotencyService->execute(
            tenantId: $this->tenant->id,
            cartId: null,
            checkoutSessionId: $this->checkoutB->id,
            operationType: 'reserve',
            idempotencyKey: 'shared-key-1',
            requestPayload: ['checkout' => 'B'],
            callback: fn () => ['session' => 'B']
        );

        $this->assertSame('A', $resA['session']);
        $this->assertSame('B', $resB['session']);
    }

    public function test_same_key_with_different_fingerprint_is_rejected(): void
    {
        $this->idempotencyService->execute(
            tenantId: $this->tenant->id,
            cartId: null,
            checkoutSessionId: $this->checkoutA->id,
            operationType: 'reserve',
            idempotencyKey: 'fp-key-1',
            requestPayload: ['payload' => 'initial'],
            callback: fn () => ['status' => 'initial']
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Idempotency key [fp-key-1] was previously used with a different request payload.');

        $this->idempotencyService->execute(
            tenantId: $this->tenant->id,
            cartId: null,
            checkoutSessionId: $this->checkoutA->id,
            operationType: 'reserve',
            idempotencyKey: 'fp-key-1',
            requestPayload: ['payload' => 'modified'],
            callback: fn () => ['status' => 'modified']
        );
    }
}
