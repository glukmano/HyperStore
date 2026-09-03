<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Carbon\CarbonImmutable;
use Modules\Marketplace\Contracts\VendorPlanSubscriptionEntitlementServiceInterface;
use Modules\Marketplace\Enums\SubscriptionStatus;
use Modules\Marketplace\Exceptions\VendorPlanSubscriptionException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorPlanSubscription;

final class VendorPlanSubscriptionEntitlementService implements VendorPlanSubscriptionEntitlementServiceInterface
{
    public function hasActiveSubscription(Vendor $vendor): bool
    {
        $subscription = $vendor->subscriptions()
            ->where('status', SubscriptionStatus::Active->value)
            ->latest('id')
            ->first();

        if ($subscription === null) {
            return false;
        }

        if ($subscription->activation_source === 'test_fake') {
            if (! app()->environment('local', 'testing')) {
                throw VendorPlanSubscriptionException::fakeSubscriptionForbiddenInProduction();
            }
        }

        return true;
    }

    public function assertSubscriptionActive(Vendor $vendor): void
    {
        if (! $this->hasActiveSubscription($vendor)) {
            throw VendorPlanSubscriptionException::autoApprovalDeniedUnpaid();
        }
    }

    public function activateSubscription(
        Vendor $vendor,
        VendorPlan $plan,
        string $activationSource,
        ?string $reference = null
    ): VendorPlanSubscription {
        if ($activationSource === 'test_fake' && ! app()->environment('local', 'testing')) {
            throw VendorPlanSubscriptionException::fakeSubscriptionForbiddenInProduction();
        }

        /** @var VendorPlanSubscription $subscription */
        $subscription = VendorPlanSubscription::create([
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'vendor_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'activation_source' => $activationSource,
            'external_subscription_reference' => $reference,
            'current_period_starts_at' => CarbonImmutable::now(),
            'current_period_ends_at' => CarbonImmutable::now()->addMonth(),
            'activated_at' => CarbonImmutable::now(),
        ]);

        return $subscription;
    }
}
