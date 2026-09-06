<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Services;

use Carbon\CarbonImmutable;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Contracts\RecurringPaymentGatewayInterface;
use Modules\Payment\Exceptions\GatewayDoesNotSupportRecurringException;
use Modules\Payment\Models\CustomerPaymentMethod;
use Modules\Subscriptions\Enums\SubscriptionStatus;
use Modules\Subscriptions\Models\Subscription;
use Modules\Subscriptions\Models\SubscriptionPlan;

/**
 * D.44: exactly the decided subscription-change scope — cancel-now,
 * cancel-at-period-end, plan-change-at-next-renewal (no proration),
 * payment-method replacement, grace reactivation. Pause/resume and
 * quantity-change are explicitly deferred, not built here.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly PaymentGatewayRegistryInterface $gatewayRegistry
    ) {}

    public function createSubscription(
        int $tenantId,
        int $customerProfileId,
        int $planId,
        int $storeId,
        int $marketId,
        int $channelId,
        CustomerPaymentMethod $paymentMethod
    ): Subscription {
        // Owner Delta §9: rejected at SETUP time, never silently attempted
        // and failed later at renewal.
        $gateway = $this->gatewayRegistry->get($paymentMethod->gateway_provider_code);
        if (! $gateway instanceof RecurringPaymentGatewayInterface) {
            throw GatewayDoesNotSupportRecurringException::forProvider($paymentMethod->gateway_provider_code);
        }

        /** @var SubscriptionPlan $plan */
        $plan = SubscriptionPlan::where('tenant_id', $tenantId)->findOrFail($planId);

        $now = CarbonImmutable::now('UTC');
        $hasTrial = $plan->trial_days !== null && $plan->trial_days > 0;
        $periodEnd = $hasTrial ? $now->addDays($plan->trial_days) : $plan->nextPeriodEnd($now);

        return Subscription::create([
            'tenant_id' => $tenantId,
            'customer_profile_id' => $customerProfileId,
            'plan_id' => $plan->id,
            'store_id' => $storeId,
            'market_id' => $marketId,
            'channel_id' => $channelId,
            'status' => $hasTrial ? SubscriptionStatus::Trialing->value : SubscriptionStatus::Active->value,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'next_billing_at' => $periodEnd,
            'cancel_at_period_end' => false,
            'payment_method_id' => $paymentMethod->id,
        ]);
    }

    public function cancelNow(Subscription $subscription): void
    {
        $subscription->status = SubscriptionStatus::Cancelled;
        $subscription->cancel_at_period_end = false;
        $subscription->save();
    }

    public function cancelAtPeriodEnd(Subscription $subscription): void
    {
        $subscription->cancel_at_period_end = true;
        $subscription->save();
    }

    public function changePlanAtNextRenewal(Subscription $subscription, int $newPlanId): void
    {
        /** @var SubscriptionPlan $newPlan */
        $newPlan = SubscriptionPlan::where('tenant_id', $subscription->tenant_id)->findOrFail($newPlanId);
        $subscription->pending_plan_id = $newPlan->id;
        $subscription->save();
    }

    public function replacePaymentMethod(Subscription $subscription, CustomerPaymentMethod $paymentMethod): void
    {
        $gateway = $this->gatewayRegistry->get($paymentMethod->gateway_provider_code);
        if (! $gateway instanceof RecurringPaymentGatewayInterface) {
            throw GatewayDoesNotSupportRecurringException::forProvider($paymentMethod->gateway_provider_code);
        }

        $subscription->payment_method_id = $paymentMethod->id;
        $subscription->save();
    }

    /**
     * A past_due/grace Subscription can be reactivated by supplying a
     * working payment method — this triggers an immediate retry of the
     * CURRENTLY-claimed (not a new) SubscriptionRenewalAttempt for the
     * still-open billing period on the next processDueRenewals() pass,
     * since next_billing_at was never advanced by the failed attempt.
     */
    public function reactivate(Subscription $subscription, ?CustomerPaymentMethod $paymentMethod = null): void
    {
        if ($paymentMethod !== null) {
            $this->replacePaymentMethod($subscription, $paymentMethod);
        }

        $subscription->status = SubscriptionStatus::Active;
        $subscription->save();
    }
}
