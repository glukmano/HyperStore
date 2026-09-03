<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Models\Order;

trait PaymentTestCaseTrait
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected Market $market;

    protected Channel $channel;

    protected User $user;

    protected ContextManager $contextManager;

    protected function setUpPaymentTest(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Payment Tenant', 'slug' => 'payment-tenant-'.uniqid(), 'status' => 'active']);
        Artisan::call('ledger:provision-system-accounts', ['--tenant' => $this->tenant->id]);
        $this->market = Market::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Payment Market',
            'code' => 'PAY-MKT-'.uniqid(),
            'is_active' => true,
            'default_currency_code' => 'EUR',
            'default_locale_code' => 'en',
            'timezone' => 'Europe/Berlin',
        ]);
        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Payment Store',
            'slug' => 'pay-store-'.uniqid(),
            'status' => 'active',
        ]);
        $this->channel = Channel::create([
            'type' => 'website',
            'name' => 'Pay Channel',
            'handle' => 'pay-web-'.uniqid(),
            'is_active' => true,
        ]);
        StoreChannel::create([
            'store_id' => $this->store->id,
            'channel_id' => $this->channel->id,
        ]);

        $this->user = User::factory()->create();

        $this->contextManager = app(ContextManager::class);
        $this->contextManager->setTenant(TenantContext::from($this->tenant->id));
    }

    protected function createGuestOrder(
        int $grandTotalMinor = 10000,
        string $currency = 'EUR',
        string $orderStatus = 'placed',
        string $paymentStatus = 'pending',
        ?string $guestTokenHash = null
    ): Order {
        return $this->createOrder(
            grandTotalMinor: $grandTotalMinor,
            currency: $currency,
            orderStatus: $orderStatus,
            paymentStatus: $paymentStatus,
            userId: null,
            guestTokenHash: $guestTokenHash
        );
    }

    protected function createOrder(
        int $grandTotalMinor = 10000,
        string $currency = 'EUR',
        string $orderStatus = 'placed',
        string $paymentStatus = 'pending',
        mixed $userId = -1,
        ?string $guestTokenHash = null
    ): Order {
        $actualUserId = $userId === -1 ? $this->user->id : $userId;

        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $actualUserId,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => $currency,
            'locale' => 'en',
            'status' => 'converted',
        ]);

        $checkout = CheckoutSession::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'uuid' => (string) Str::uuid(),
            'status' => 'completed',
            'cart_version' => 1,
            'currency' => $currency,
        ]);

        return Order::create([
            'tenant_id' => $this->tenant->id,
            'checkout_id' => $checkout->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $actualUserId,
            'guest_token_hash' => $guestTokenHash,
            'customer_email' => 'customer@example.com',
            'customer_first_name' => 'John',
            'customer_last_name' => 'Doe',
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => $currency,
            'locale' => 'en',
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => $grandTotalMinor,
            'discount_total_minor' => 0,
            'shipping_total_minor' => 0,
            'tax_total_minor' => 0,
            'grand_total_minor' => $grandTotalMinor,
            'customer_snapshot' => ['email' => 'customer@example.com'],
            'shipping_address_snapshot' => ['country' => 'DE'],
            'billing_address_snapshot' => ['country' => 'DE'],
            'pricing_snapshot' => ['total' => $grandTotalMinor],
            'tax_snapshot' => [],
            'promotion_snapshot' => [],
            'shipping_snapshot' => [],
            'fulfillment_snapshot' => [],
            'reservation_references' => [],
            'version' => 1,
            'placed_at' => now(),
        ]);
    }
}
