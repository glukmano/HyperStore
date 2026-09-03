<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

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
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Order\Models\Order;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;

trait LedgerTestCaseTrait
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected Market $market;

    protected Channel $channel;

    protected User $user;

    protected ContextManager $contextManager;

    protected function setUpLedgerTest(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Ledger Tenant', 'slug' => 'ledger-tenant-'.uniqid(), 'status' => 'active']);
        $this->market = Market::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ledger Market',
            'code' => 'LM-'.uniqid(),
            'is_active' => true,
            'default_currency_code' => 'EUR',
            'default_locale_code' => 'en',
            'timezone' => 'Europe/Berlin',
        ]);
        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ledger Store',
            'slug' => 'ledger-store-'.uniqid(),
            'status' => 'active',
        ]);
        $this->channel = Channel::create([
            'type' => 'website',
            'name' => 'Web',
            'handle' => 'ledger-web-'.uniqid(),
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

    protected function provisionSystemAccounts(?int $tenantId = null): void
    {
        $targetTenantId = $tenantId ?? (int) $this->tenant->id;
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($targetTenantId);
    }

    protected function createOrder(int $grandTotalMinor = 5000, string $currency = 'EUR'): Order
    {
        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
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
            'currency' => $currency,
            'locale' => 'en',
            'status' => 'completed',
            'grand_total_minor' => $grandTotalMinor,
        ]);

        return Order::create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-'.strtoupper(uniqid()),
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'checkout_id' => $checkout->id,
            'currency' => $currency,
            'locale' => 'en',
            'order_status' => 'placed',
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => $grandTotalMinor,
            'discount_total_minor' => 0,
            'shipping_total_minor' => 0,
            'tax_total_minor' => 0,
            'grand_total_minor' => $grandTotalMinor,
            'customer_snapshot' => ['email' => 'test@example.com'],
            'placed_at' => now(),
        ]);
    }

    protected function createPaymentWithTransaction(
        Order $order,
        string $operationType = 'purchase',
        string $status = 'success',
        int $amountMinor = 5000,
        string $currency = 'EUR'
    ): array {
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'status' => $operationType === 'purchase' || $operationType === 'capture' ? 'captured' : 'pending',
            'captured_amount_minor' => $operationType === 'purchase' || $operationType === 'capture' ? $amountMinor : 0,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => $operationType,
            'status' => $status,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'provider_code' => 'fake',
            'provider_reference' => 'ref-'.uniqid(),
        ]);

        return [$payment, $tx];
    }
}
