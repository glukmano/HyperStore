<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\VendorPlanChangeServiceInterface;
use Modules\Marketplace\Enums\VendorInvitationStatus;
use Modules\Marketplace\Exceptions\CrossTenantMarketplaceException;
use Modules\Marketplace\Exceptions\VendorPlanDowngradeQuotaException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorInvitation;
use Modules\Marketplace\Models\VendorListing;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorUser;

final readonly class VendorPlanChangeService implements VendorPlanChangeServiceInterface
{
    public function __construct(
        private MarketplaceConcurrencyBarrierInterface $barrier = new NoOpMarketplaceConcurrencyBarrier
    ) {}

    public function changePlan(int $tenantId, int $vendorId, int $targetPlanId): Vendor
    {
        return DB::transaction(function () use ($tenantId, $vendorId, $targetPlanId): Vendor {
            // 1. Lock Vendor aggregate row
            /** @var Vendor $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($vendorId);

            // 2. Load target Plan under same Tenant
            /** @var VendorPlan $targetPlan */
            $targetPlan = VendorPlan::where('tenant_id', $tenantId)->findOrFail($targetPlanId);

            if ($targetPlan->tenant_id !== $tenantId) {
                throw new CrossTenantMarketplaceException("Target plan [{$targetPlanId}] does not belong to tenant [{$tenantId}].");
            }

            // 3. Calculate current quota usage:
            // a) Staff usage (active vendor users + pending non-expired invitations)
            $activeStaffCount = VendorUser::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('is_active', true)
                ->count();

            $pendingInvitesCount = VendorInvitation::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('status', VendorInvitationStatus::Pending->value)
                ->where('expires_at', '>', CarbonImmutable::now())
                ->count();

            $totalStaffUsage = $activeStaffCount + $pendingInvitesCount;

            // b) Listing usage
            $currentListingCount = VendorListing::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->count();

            $this->barrier->wait('vendor_plan_change_usage_calculated');

            // 4 & 5. Compare current usage to target-plan limits (fail-closed, no grandfathering)
            if ($targetPlan->staff_limit !== null && $totalStaffUsage > $targetPlan->staff_limit) {
                throw VendorPlanDowngradeQuotaException::staffLimitExceeded($totalStaffUsage, $targetPlan->staff_limit);
            }

            if ($targetPlan->product_limit !== null && $currentListingCount > $targetPlan->product_limit) {
                throw VendorPlanDowngradeQuotaException::listingLimitExceeded($currentListingCount, $targetPlan->product_limit);
            }

            // 6. Assign plan
            $vendor->vendor_plan_id = $targetPlan->id;
            $vendor->save();

            return $vendor;
        });
    }
}
