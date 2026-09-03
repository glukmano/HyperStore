<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\TenantEntitlementServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantResourceEntitlementGuardInterface;
use App\Core\SuperAdmin\Exceptions\TenantResourceQuotaExceededException;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

final readonly class TenantResourceEntitlementGuard implements TenantResourceEntitlementGuardInterface
{
    public function __construct(
        private TenantEntitlementServiceInterface $entitlementService
    ) {}

    public function admit(int $tenantId, string $resourceKey, callable $mutation): mixed
    {
        return DB::transaction(function () use ($tenantId, $resourceKey, $mutation) {
            /** @var Tenant $tenant */
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->findOrFail($tenantId);

            $effectiveLimit = $this->entitlementService->getEffectiveLimit($tenantId, $resourceKey);
            $currentUsage = $this->entitlementService->getCurrentUsage($tenantId, $resourceKey);

            if ($currentUsage >= $effectiveLimit) {
                throw TenantResourceQuotaExceededException::limitReached(
                    $tenantId,
                    $resourceKey,
                    $currentUsage,
                    $effectiveLimit
                );
            }

            return $mutation();
        });
    }
}
