<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Modules\Marketplace\Contracts\VendorApprovalPolicyInterface;
use Modules\Marketplace\Contracts\VendorPlanSubscriptionEntitlementServiceInterface;
use Modules\Marketplace\Models\Vendor;

final class VendorApprovalPolicy implements VendorApprovalPolicyInterface
{
    public function __construct(
        private readonly VendorPlanSubscriptionEntitlementServiceInterface $subscriptionService
    ) {}

    public function canAutoApprove(Vendor $vendor): bool
    {
        $plan = $vendor->plan;

        // If plan has auto_approval disabled, fail closed
        if (! $plan->auto_approval) {
            return false;
        }

        // Check if plan has any paid prices
        $hasPaidPrice = $plan->prices()->where('monthly_fee_minor', '>', 0)->exists();

        // Free plan -> unconditionally manual approval required
        if (! $hasPaidPrice) {
            return false;
        }

        // Paid plan -> auto-approval ONLY if subscription is active with valid provenance
        return $this->subscriptionService->hasActiveSubscription($vendor);
    }
}
