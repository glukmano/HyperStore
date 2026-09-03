<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\TenantEntitlementServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\TenantLicenseOverrideException;
use App\Core\SuperAdmin\Exceptions\TenantLicensePlanReassignmentException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\SuperAdmin\Models\TenantLicense;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TenantLicenseService implements TenantLicenseServiceInterface
{
    public function __construct(
        private TenantEntitlementServiceInterface $entitlementService
    ) {}

    public function assignLicense(
        int $tenantId,
        int $planId,
        array $overrideLimits = [],
        array $overrideFeatures = []
    ): TenantLicense {
        return DB::transaction(function () use ($tenantId, $planId, $overrideLimits, $overrideFeatures): TenantLicense {
            /** @var Tenant $tenant */
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->findOrFail($tenantId);

            /** @var PlatformSaasPlan $plan */
            $plan = PlatformSaasPlan::findOrFail($planId);
            if (! $plan->isActive()) {
                throw TenantLicensePlanReassignmentException::planNotActive($planId, $plan->status);
            }

            /** @var TenantLicense $license */
            $license = TenantLicense::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'platform_saas_plan_id' => $plan->id,
                    'license_key_hash' => hash('sha256', Str::random(40)),
                    'status' => 'active',
                    'valid_until' => now()->addYear(),
                    'override_limits' => $overrideLimits,
                    'override_features' => $overrideFeatures,
                ]
            );

            return $license;
        });
    }

    public function updateOverrides(int $tenantId, array $overrideLimits, array $overrideFeatures): TenantLicense
    {
        return DB::transaction(function () use ($tenantId, $overrideLimits, $overrideFeatures): TenantLicense {
            /** @var Tenant $tenant */
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->findOrFail($tenantId);

            /** @var TenantLicense $license */
            $license = TenantLicense::with('plan')->where('tenant_id', $tenantId)->firstOrFail();

            $planLimits = $license->plan->limits ?? [];
            $allKeys = array_unique(array_merge(array_keys($overrideLimits), array_keys($planLimits)));

            foreach ($allKeys as $resourceKey) {
                if (! in_array($resourceKey, ['max_stores', 'max_vendors', 'max_products'], true)) {
                    continue;
                }

                $newEffectiveLimit = array_key_exists($resourceKey, $overrideLimits)
                    ? (int) $overrideLimits[$resourceKey]
                    : (isset($planLimits[$resourceKey]) ? (int) $planLimits[$resourceKey] : null);

                if ($newEffectiveLimit !== null) {
                    $usage = $this->entitlementService->getCurrentUsage($tenantId, $resourceKey);
                    if ($usage > $newEffectiveLimit) {
                        throw TenantLicenseOverrideException::limitBelowCurrentUsage(
                            $tenantId,
                            $resourceKey,
                            $usage,
                            $newEffectiveLimit
                        );
                    }
                }
            }

            $license->override_limits = $overrideLimits;
            $license->override_features = $overrideFeatures;
            $license->save();

            return $license;
        });
    }

    public function reassignPlan(int $tenantId, int $targetPlanId): TenantLicense
    {
        return DB::transaction(function () use ($tenantId, $targetPlanId): TenantLicense {
            /** @var Tenant $tenant */
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->findOrFail($tenantId);

            /** @var PlatformSaasPlan $targetPlan */
            $targetPlan = PlatformSaasPlan::findOrFail($targetPlanId);
            if (! $targetPlan->isActive()) {
                throw TenantLicensePlanReassignmentException::planNotActive($targetPlanId, $targetPlan->status);
            }

            /** @var TenantLicense $license */
            $license = TenantLicense::where('tenant_id', $tenantId)->firstOrFail();

            $targetLimits = $targetPlan->limits ?? [];
            $overrides = $license->override_limits ?? [];

            foreach ($targetLimits as $resourceKey => $targetLimit) {
                if (! in_array($resourceKey, ['max_stores', 'max_vendors', 'max_products'], true)) {
                    continue;
                }

                $effectiveLimit = array_key_exists($resourceKey, $overrides) && $overrides[$resourceKey] !== null
                    ? (int) $overrides[$resourceKey]
                    : (int) $targetLimit;

                $usage = $this->entitlementService->getCurrentUsage($tenantId, $resourceKey);
                if ($usage > $effectiveLimit) {
                    throw TenantLicensePlanReassignmentException::usageExceedsTargetPlanLimits(
                        $tenantId,
                        $resourceKey,
                        $usage,
                        $effectiveLimit
                    );
                }
            }

            $license->platform_saas_plan_id = $targetPlan->id;
            $license->save();

            return $license;
        });
    }

    public function suspendLicense(int $tenantId): TenantLicense
    {
        return DB::transaction(function () use ($tenantId): TenantLicense {
            /** @var TenantLicense $license */
            $license = TenantLicense::where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
            $license->status = 'suspended';
            $license->save();

            return $license;
        });
    }

    public function reactivateLicense(int $tenantId): TenantLicense
    {
        return DB::transaction(function () use ($tenantId): TenantLicense {
            /** @var TenantLicense $license */
            $license = TenantLicense::where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
            $license->status = 'active';
            $license->save();

            return $license;
        });
    }
}
