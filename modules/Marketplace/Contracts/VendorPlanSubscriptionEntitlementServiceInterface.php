<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorPlanSubscription;

interface VendorPlanSubscriptionEntitlementServiceInterface
{
    public function hasActiveSubscription(Vendor $vendor): bool;

    public function assertSubscriptionActive(Vendor $vendor): void;

    public function activateSubscription(Vendor $vendor, VendorPlan $plan, string $activationSource, ?string $reference = null): VendorPlanSubscription;
}
