<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\TenantEntitlementServiceInterface;
use App\Core\SuperAdmin\Exceptions\MissingEntitlementConfigurationException;
use App\Core\SuperAdmin\Exceptions\TenantLicenseInactiveException;
use App\Core\SuperAdmin\Models\TenantLicense;
use InvalidArgumentException;
use Modules\Catalog\Models\Product;
use Modules\Marketplace\Models\Vendor;

final readonly class TenantEntitlementService implements TenantEntitlementServiceInterface
{
    public function getEffectiveLimit(int $tenantId, string $resourceKey): int
    {
        /** @var ?TenantLicense $license */
        $license = TenantLicense::with('plan')->where('tenant_id', $tenantId)->first();
        if ($license === null) {
            throw MissingEntitlementConfigurationException::forResource($tenantId, $resourceKey);
        }

        if (! $license->isActive()) {
            throw TenantLicenseInactiveException::forTenant($tenantId, $license->status);
        }

        // 1. Explicit license override
        $overrideLimits = $license->override_limits ?? [];
        if (array_key_exists($resourceKey, $overrideLimits) && $overrideLimits[$resourceKey] !== null) {
            return (int) $overrideLimits[$resourceKey];
        }

        // 2. Authoritative SaaS plan default
        $planLimits = $license->plan->limits ?? [];
        if (array_key_exists($resourceKey, $planLimits) && $planLimits[$resourceKey] !== null) {
            return (int) $planLimits[$resourceKey];
        }

        // 3. Fail closed: missing key is NOT zero
        throw MissingEntitlementConfigurationException::forResource($tenantId, $resourceKey);
    }

    public function getCurrentUsage(int $tenantId, string $resourceKey): int
    {
        return match ($resourceKey) {
            'max_stores' => Store::where('tenant_id', $tenantId)->count(),
            'max_vendors' => Vendor::where('tenant_id', $tenantId)
                ->whereNotIn('operational_status', ['terminated'])
                ->count(),
            'max_products' => Product::where('tenant_id', $tenantId)
                ->whereNotIn('status', ['archived'])
                ->count(),
            default => throw new InvalidArgumentException("Unknown quota resource key [{$resourceKey}]."),
        };
    }

    public function hasFeature(int $tenantId, string $featureKey): bool
    {
        /** @var ?TenantLicense $license */
        $license = TenantLicense::with('plan')->where('tenant_id', $tenantId)->first();
        if ($license === null || ! $license->isActive()) {
            return false;
        }

        $overrideFeatures = $license->override_features ?? [];
        if (array_key_exists($featureKey, $overrideFeatures)) {
            return (bool) $overrideFeatures[$featureKey];
        }

        $planFeatures = $license->plan->feature_entitlements ?? [];
        if (array_key_exists($featureKey, $planFeatures)) {
            return (bool) $planFeatures[$featureKey];
        }

        return false;
    }
}
