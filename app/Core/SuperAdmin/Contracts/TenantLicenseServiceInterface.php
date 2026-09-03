<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use App\Core\SuperAdmin\Exceptions\TenantLicenseInactiveException;
use App\Core\SuperAdmin\Models\TenantLicense;

interface TenantLicenseServiceInterface
{
    /**
     * Asserts that an authoritative, active, unexpired license exists for the given tenant.
     * Fails closed if missing, suspended, or expired.
     *
     * @throws TenantLicenseInactiveException
     */
    public function assertActiveForTenant(int $tenantId): TenantLicense;

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
