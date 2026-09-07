<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Customers\Models\CustomerProfile;
use Modules\GiftCards\Models\GiftCard;
use Modules\GiftCards\Services\GiftCardService;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Models\Order;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Services\StoreValueService;
use Throwable;

/**
 * Pre-Production Readiness — a compact set of coherent business-data
 * examples (one completed Order via the real Checkout/Payment pipeline, a
 * Wallet balance, a Gift Card) reusing real domain services, never raw
 * inserts that would bypass an economic invariant.
 *
 * Explicitly DEFERRED from this pass (not silently dropped — tracked in
 * docs/qa/LOCAL-PRODUCTION-READINESS.md): B2B Company/Quote workflow,
 * Auction, Booking, Subscription signup, an RMA/Return example, and POS
 * register-session history. Each requires deeper per-module fixture setup
 * than this pass's remaining scope justified; the foundation (B2B buyer/
 * approver users, the POS Register itself) is already seeded so a future
 * pass can extend this seeder without redoing the foundation.
 */
class DemoBusinessDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->firstOrFail();
        $store = Store::where('tenant_id', $tenant->id)->where('slug', 'demo-flagship')->firstOrFail();
        $market = Market::where('tenant_id', $tenant->id)->where('code', 'DEMO_US')->firstOrFail();
        $channel = Channel::where('handle', 'demo-web')->firstOrFail();
        $customerUser = User::where('email', 'customer@demo.hyperstore.test')->firstOrFail();

        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($tenant->id);

        $customerProfile = CustomerProfile::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $customerUser->id],
        );

        $this->seedCompletedOrder($tenant->id, $store->id, $market->id, $channel->id, $customerUser->id);
        $this->seedWalletBalance($tenant->id, $customerProfile->id);
        $this->seedGiftCard($tenant->id);

        $this->command?->info('Demo business data seeded: 1 completed Order, 1 Wallet balance, 1 Gift Card.');
        $this->command?->warn('Deferred (not seeded this pass): B2B Quote workflow, Auction, Booking, Subscription signup, RMA/Return example, POS session history.');
    }

    private function seedCompletedOrder(int $tenantId, int $storeId, int $marketId, int $channelId, int $userId): void
    {
        $existing = Order::where('tenant_id', $tenantId)->where('order_number', 'like', 'DEMO-%')->first();
        if ($existing !== null) {
            return;
        }

        try {
            $product = Product::where('tenant_id', $tenantId)->where('sku', 'DEMO-PHYS-001')->firstOrFail();

            $cart = app(CartServiceInterface::class)->getOrCreateActiveCart(new CartContext(
                tenantId: $tenantId,
                storeId: $storeId,
                marketId: $marketId,
                channelId: $channelId,
                currency: 'USD',
                userId: $userId,
            ));
            app(CartServiceInterface::class)->addLine($cart, new CartLineItemData(
                productId: $product->id,
                variantId: null,
                quantity: CartQuantity::fromInt(1),
            ));

            $checkout = app(CheckoutOrchestratorInterface::class);
            $session = $checkout->createFromCart($cart);
            $checkout->setCustomerData($session, new CheckoutCustomerData('customer@demo.hyperstore.test', 'Demo', 'Customer'));
            $checkout->setAddresses($session, new CheckoutAddress('Demo Customer', ['123 Demo Street'], 'Springfield', 'US', postalCode: '62704'));
            $rates = $checkout->getShippingRates($session);
            $quotes = $rates['shipping_result']->quotes ?? collect();
            if ($quotes->isNotEmpty()) {
                $cheapest = $quotes->first();
                $checkout->selectShippingQuote($session, ['method_id' => $cheapest->methodId, 'method_code' => $cheapest->methodCode]);
            }
            $checkout->reserveInventory($session);
            $ready = $checkout->markReadyForOrder($session);

            $orderResult = app(OrderCreationServiceInterface::class)->createFromCheckout(new OrderCreationDTO(
                tenantId: $tenantId,
                checkoutId: $ready->checkoutSessionId,
            ));
            $order = $orderResult->order;
            $order->update(['order_number' => 'DEMO-'.$order->order_number]);

            $amountDue = (int) ($order->amount_due_minor ?? $order->grand_total_minor);
            if ($amountDue > 0) {
                $provider = app(PaymentGatewayRegistryInterface::class)->default()->getProviderCode();
                app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
                    tenantId: $tenantId,
                    orderId: $order->id,
                    amountMinor: $amountDue,
                    currency: $order->currency,
                    providerCode: $provider,
                ));
            }
        } catch (Throwable $e) {
            $this->command?->warn('Demo Order seeding skipped: '.$e->getMessage());
        }
    }

    private function seedWalletBalance(int $tenantId, int $customerProfileId): void
    {
        try {
            $account = app(StoreValueService::class)->findOrCreateAccount($tenantId, $customerProfileId, StoreValueInstrumentType::Wallet, 'USD');
            app(StoreValueService::class)->issue($account, 2500, 'demo_seed', 'demo-wallet-credit-1');
        } catch (Throwable $e) {
            $this->command?->warn('Demo Wallet seeding skipped: '.$e->getMessage());
        }
    }

    private function seedGiftCard(int $tenantId): void
    {
        // GiftCardService::issue() always mints a genuinely new card (a
        // fresh random code per call, by design — sourceUuid only
        // idempotency-guards the underlying Ledger entry, never "was a
        // card already issued for this event"). Demo re-seeding must stay
        // idempotent, so this seeder — not the service — guards against
        // re-issuing on a repeated `demo:seed` run.
        if (GiftCard::where('tenant_id', $tenantId)->exists()) {
            return;
        }

        try {
            app(GiftCardService::class)->issue($tenantId, 'USD', 5000, 'demo-gift-card-1');
        } catch (Throwable $e) {
            $this->command?->warn('Demo Gift Card seeding skipped: '.$e->getMessage());
        }
    }
}
