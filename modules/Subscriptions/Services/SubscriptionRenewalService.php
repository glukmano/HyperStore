<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Services;

use App\Core\Markets\Models\Market;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Customers\Models\CustomerProfile;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Models\OrderItem;
use Modules\Payment\Exceptions\GatewayDoesNotSupportRecurringException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\CustomerPaymentMethod;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\Subscriptions\Enums\SubscriptionRenewalStatus;
use Modules\Subscriptions\Enums\SubscriptionStatus;
use Modules\Subscriptions\Models\Subscription;
use Modules\Subscriptions\Models\SubscriptionPlan;
use Modules\Subscriptions\Models\SubscriptionRenewalAttempt;

/**
 * Owner Delta §11/D.42: serialization begins BEFORE any charge attempt — a
 * billing period is claimed via the DB-unique
 * (subscription_id, billing_period_start) row BEFORE a Cart/Order is built
 * or a provider is called. No second Order/commerce engine — this is
 * entirely the EXISTING Checkout/OrderCreationService pipeline driven
 * programmatically instead of by a human clicking through Checkout pages.
 *
 * D.43 dunning: a failed/unknown attempt for the CURRENT (un-advanced)
 * period is retried by re-claiming the SAME attempt row (never a second
 * row for the same period — the unique constraint is never violated,
 * because this is an UPDATE of the existing row, not an INSERT) once its
 * scheduled retry day arrives, using a freshly-derived idempotency key per
 * attempt number. After the plan's finite dunning_retry_days list is
 * exhausted, the subscription suspends — never retried indefinitely.
 */
final class SubscriptionRenewalService
{
    public function __construct(
        private readonly CartServiceInterface $cartService,
        private readonly CheckoutOrchestratorInterface $checkoutOrchestrator,
        private readonly OrderCreationServiceInterface $orderCreationService,
        private readonly PaymentInitiationService $paymentInitiation
    ) {}

    private function dbNow(): CarbonImmutable
    {
        /** @var object{now: string} $row */
        $row = DB::selectOne('select current_timestamp as now');

        return CarbonImmutable::parse($row->now)->utc();
    }

    public function processDueRenewals(int $tenantId): void
    {
        $now = $this->dbNow();

        // New-period renewals: next_billing_at has arrived for a
        // subscription with no attempt yet for its (un-advanced) period.
        $due = Subscription::where('tenant_id', $tenantId)
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value])
            ->where('cancel_at_period_end', false)
            ->where('next_billing_at', '<=', $now)
            ->get();

        foreach ($due as $subscription) {
            $this->claimAndProcess($subscription, CarbonImmutable::instance($subscription->current_period_end), $now);
        }

        // Dunning retries: past_due subscriptions whose most recent attempt
        // failed/is unknown and whose next configured retry day has come.
        $pastDue = Subscription::where('tenant_id', $tenantId)
            ->where('status', SubscriptionStatus::PastDue->value)
            ->get();

        foreach ($pastDue as $subscription) {
            $this->maybeRetryPastDue($subscription, $now);
        }

        // D.44: cancel_at_period_end subscriptions whose period has ended
        // never renew — they transition straight to cancelled instead.
        Subscription::where('tenant_id', $tenantId)
            ->where('cancel_at_period_end', true)
            ->where('current_period_end', '<=', $now)
            ->whereNotIn('status', [SubscriptionStatus::Cancelled->value])
            ->update(['status' => SubscriptionStatus::Cancelled->value]);
    }

    private function claimAndProcess(Subscription $subscription, CarbonImmutable $billingPeriodStart, CarbonImmutable $now, int $attemptNumber = 1): void
    {
        $providerIdempotencyKey = "subscription:{$subscription->id}:period:{$billingPeriodStart->toIso8601String()}".($attemptNumber > 1 ? ":retry{$attemptNumber}" : '');

        try {
            $attempt = SubscriptionRenewalAttempt::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'billing_period_start' => $billingPeriodStart,
                'status' => SubscriptionRenewalStatus::Claimed->value,
                'provider_idempotency_key' => $providerIdempotencyKey,
                'claimed_at' => $now,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'uq_subscription_renewal_period')) {
                // Another worker already owns this period — stop
                // immediately, before building anything.
                return;
            }
            throw $e;
        }

        $this->executeClaimedRenewal($subscription, $attempt);
    }

    private function maybeRetryPastDue(Subscription $subscription, CarbonImmutable $now): void
    {
        $billingPeriodStart = CarbonImmutable::instance($subscription->current_period_end);

        /** @var SubscriptionRenewalAttempt|null $attempt */
        $attempt = SubscriptionRenewalAttempt::where('subscription_id', $subscription->id)
            ->where('billing_period_start', $billingPeriodStart)
            ->first();

        if ($attempt === null || ! in_array($attempt->status, [SubscriptionRenewalStatus::Failed, SubscriptionRenewalStatus::Unknown], true)) {
            return;
        }

        $plan = $subscription->plan;
        $retryDays = $plan->dunning_retry_days ?? [1, 3, 7];
        $attemptNumber = (int) (SubscriptionRenewalAttempt::where('subscription_id', $subscription->id)
            ->where('billing_period_start', $billingPeriodStart)
            ->count());

        if ($attemptNumber > count($retryDays)) {
            $subscription->status = SubscriptionStatus::Suspended;
            $subscription->save();

            return;
        }

        $daysSinceClaim = $attempt->resolved_at !== null ? $attempt->resolved_at->diffInDays($now) : 0;
        if ($daysSinceClaim < $retryDays[$attemptNumber - 1]) {
            return;
        }

        // Re-claim the SAME row (an UPDATE, never a second INSERT for this
        // period) with a freshly-derived idempotency key for this attempt.
        $attempt->status = SubscriptionRenewalStatus::Claimed;
        $attempt->provider_idempotency_key = "subscription:{$subscription->id}:period:{$billingPeriodStart->toIso8601String()}:retry{$attemptNumber}";
        $attempt->failure_reason = null;
        $attempt->claimed_at = $now;
        $attempt->resolved_at = null;
        $attempt->save();

        $this->executeClaimedRenewal($subscription, $attempt);
    }

    private function executeClaimedRenewal(Subscription $subscription, SubscriptionRenewalAttempt $attempt): void
    {
        try {
            /** @var SubscriptionPlan $plan */
            $plan = SubscriptionPlan::findOrFail($subscription->plan_id);
            if ($subscription->pending_plan_id !== null) {
                /** @var SubscriptionPlan $pendingPlan */
                $pendingPlan = SubscriptionPlan::findOrFail($subscription->pending_plan_id);
                $plan = $pendingPlan;
                $subscription->plan_id = $pendingPlan->id;
                $subscription->pending_plan_id = null;
            }

            /** @var CustomerProfile $customerProfile */
            $customerProfile = CustomerProfile::findOrFail($subscription->customer_profile_id);
            /** @var User $customerUser */
            $customerUser = User::findOrFail($customerProfile->user_id);
            /** @var Market $market */
            $market = Market::findOrFail($subscription->market_id);

            // A dedicated, isolated Cart for this renewal — NEVER the
            // customer's own live storefront cart (CartService::
            // getOrCreateActiveCart() would otherwise merge this synthetic
            // purchase into whatever the customer happens to be shopping
            // for right now).
            $cart = Cart::create([
                'tenant_id' => $subscription->tenant_id,
                'user_id' => $customerProfile->user_id,
                'store_id' => $subscription->store_id,
                'market_id' => $subscription->market_id,
                'channel_id' => $subscription->channel_id,
                'currency' => $market->default_currency_code,
                'status' => 'active',
            ]);

            $this->cartService->addLine($cart, new CartLineItemData(
                productId: $plan->product_id,
                variantId: null,
                quantity: CartQuantity::fromInt(1),
                customizations: ['subscription_id' => $subscription->id, 'billing_period_start' => $attempt->billing_period_start->toIso8601String()]
            ));

            $session = $this->checkoutOrchestrator->createFromCart($cart);
            $session = $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData(
                email: $customerUser->email,
                firstName: $customerUser->name !== '' ? $customerUser->name : 'Customer',
                lastName: ''
            ));
            $ready = $this->checkoutOrchestrator->markReadyForOrder($session);

            $result = $this->orderCreationService->createFromCheckout(new OrderCreationDTO(
                tenantId: $ready->tenantId,
                checkoutId: $ready->checkoutSessionId
            ));
            $order = $result->order;

            $attempt->order_id = $order->id;
            $attempt->save();

            $order->loadMissing('items');
            foreach ($order->items as $item) {
                /** @var OrderItem $item */
                if ((int) $item->product_id === (int) $plan->product_id) {
                    $item->subscription_id = (int) $subscription->id;
                    $item->billing_period_start_snapshot = $attempt->billing_period_start;
                    $item->billing_period_end_snapshot = $plan->nextPeriodEnd($attempt->billing_period_start);
                    $item->plan_snapshot = ['plan_id' => $plan->id, 'name' => $plan->name, 'billing_interval' => $plan->billing_interval, 'unit_price_minor' => $item->unit_price_minor];
                    $item->save();
                }
            }

            /** @var CustomerPaymentMethod $paymentMethod */
            $paymentMethod = CustomerPaymentMethod::findOrFail($subscription->payment_method_id);

            $response = $this->paymentInitiation->initiateOffSessionPayment(
                tenantId: $subscription->tenant_id,
                orderId: $order->id,
                amountMinor: $order->amount_due_minor ?? $order->grand_total_minor,
                currency: $order->currency,
                gatewayProviderCode: $paymentMethod->gateway_provider_code,
                gatewayReference: $paymentMethod->gateway_reference,
                providerIdempotencyKey: $attempt->provider_idempotency_key
            );

            $succeeded = ($response['status'] ?? null) === 'captured';

            if ($succeeded) {
                $attempt->status = SubscriptionRenewalStatus::Succeeded;
                $attempt->resolved_at = $this->dbNow();
                $attempt->save();

                $newPeriodEnd = $plan->nextPeriodEnd($attempt->billing_period_start);
                $subscription->status = SubscriptionStatus::Active;
                $subscription->current_period_start = $attempt->billing_period_start;
                $subscription->current_period_end = $newPeriodEnd;
                $subscription->next_billing_at = $newPeriodEnd;
                $subscription->save();
            } else {
                $attempt->status = SubscriptionRenewalStatus::Failed;
                $attempt->failure_reason = (string) ($response['normalized_error_code'] ?? 'declined');
                $attempt->resolved_at = $this->dbNow();
                $attempt->save();

                if ($subscription->status !== SubscriptionStatus::Suspended) {
                    $subscription->status = SubscriptionStatus::PastDue;
                    $subscription->save();
                }
            }
        } catch (GatewayDoesNotSupportRecurringException $e) {
            $attempt->status = SubscriptionRenewalStatus::Failed;
            $attempt->failure_reason = $e->getMessage();
            $attempt->resolved_at = $this->dbNow();
            $attempt->save();

            if ($subscription->status !== SubscriptionStatus::Suspended) {
                $subscription->status = SubscriptionStatus::PastDue;
                $subscription->save();
            }
        } catch (PaymentReconciliationPendingException) {
            // Owner Delta §11: an explicit `unknown` outcome, never
            // inferred as success or silently retried merely from
            // uncertainty.
            $attempt->status = SubscriptionRenewalStatus::Unknown;
            $attempt->resolved_at = $this->dbNow();
            $attempt->save();
        }
    }
}
