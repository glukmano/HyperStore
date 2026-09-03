<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use App\Core\SuperAdmin\Models\TenantLicense;

interface TenantLicenseServiceInterface
{
    /**
     * @param  array<string, int>  $overrideLimits
     * @param  array<string, mixed>  $overrideFeatures
     */
    public function assignLicense(int $tenantId, int $planId, array $overrideLimits = [], array $overrideFeatures = []): TenantLicense;

    /**
     * @param  array<string, int>  $overrideLimits
     * @param  array<string, mixed>  $overrideFeatures
     */
    public function updateOverrides(int $tenantId, array $overrideLimits, array $overrideFeatures): TenantLicense;

    public function reassignPlan(int $tenantId, int $targetPlanId): TenantLicense;

    public function suspendLicense(int $tenantId): TenantLicense;

    public function reactivateLicense(int $tenantId): TenantLicense;
}
