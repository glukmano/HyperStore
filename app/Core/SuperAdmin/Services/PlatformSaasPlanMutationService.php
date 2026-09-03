<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\PlatformSaasPlanMutationServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantEntitlementServiceInterface;
use App\Core\SuperAdmin\Exceptions\SaasPlanLimitReductionException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\SuperAdmin\Models\TenantLicense;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class PlatformSaasPlanMutationService implements PlatformSaasPlanMutationServiceInterface
{
    public function __construct(
        private TenantEntitlementServiceInterface $entitlementService
    ) {}

    public function updateHardLimits(int $planId, array $limits): PlatformSaasPlan
    {
        return DB::transaction(function () use ($planId, $limits): PlatformSaasPlan {
            /** @var PlatformSaasPlan $plan */
            $plan = PlatformSaasPlan::where('id', $planId)->lockForUpdate()->findOrFail($planId);

            $currentLimits = $plan->limits ?? [];

            // Identify any reduced limits
            foreach ($limits as $resourceKey => $proposedLimit) {
                $proposedLimitInt = (int) $proposedLimit;
                $currentLimitInt = isset($currentLimits[$resourceKey]) ? (int) $currentLimits[$resourceKey] : null;

                // If limit is reduced or introduced at a lower ceiling, validate inheriting tenants
                if ($currentLimitInt === null || $proposedLimitInt < $currentLimitInt) {
                    $this->validateInheritingTenants($planId, $resourceKey, $proposedLimitInt);
                }
            }

            $plan->limits = array_merge($currentLimits, $limits);
            $plan->save();

            return $plan;
        });
    }

    public function updateFeatureEntitlements(int $planId, array $features): PlatformSaasPlan
    {
        return DB::transaction(function () use ($planId, $features): PlatformSaasPlan {
            /** @var PlatformSaasPlan $plan */
            $plan = PlatformSaasPlan::where('id', $planId)->lockForUpdate()->findOrFail($planId);

            $currentFeatures = $plan->feature_entitlements ?? [];
            $plan->feature_entitlements = array_merge($currentFeatures, $features);
            $plan->save();

            return $plan;
        });
    }

    private function validateInheritingTenants(int $planId, string $resourceKey, int $proposedLimit): void
    {
        /** @var Collection<int, TenantLicense> $licenses */
        $licenses = TenantLicense::where('platform_saas_plan_id', $planId)
            ->where('status', 'active')
            ->get();

        // Filter out tenants that have an explicit override for this key
        $inheritingTenantIds = $licenses->filter(function (TenantLicense $license) use ($resourceKey): bool {
            $overrides = $license->override_limits ?? [];

            return ! array_key_exists($resourceKey, $overrides) || $overrides[$resourceKey] === null;
        })->pluck('tenant_id')->unique()->sort()->values();

        // Lock affected tenants in deterministic ascending primary-key order to eliminate deadlocks
        foreach ($inheritingTenantIds as $tenantId) {
            /** @var Tenant $tenant */
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->findOrFail($tenantId);

            $usage = $this->entitlementService->getCurrentUsage((int) $tenantId, $resourceKey);
            if ($usage > $proposedLimit) {
                throw SaasPlanLimitReductionException::tenantUsageExceedsProposedLimit(
                    (int) $tenantId,
                    $resourceKey,
                    $usage,
                    $proposedLimit
                );
            }
        }
    }
}
