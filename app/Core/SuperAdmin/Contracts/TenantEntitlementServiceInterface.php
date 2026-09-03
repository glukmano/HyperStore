<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

interface TenantEntitlementServiceInterface
{
    public function getEffectiveLimit(int $tenantId, string $resourceKey): int;

    public function getCurrentUsage(int $tenantId, string $resourceKey): int;

    public function hasFeature(int $tenantId, string $featureKey): bool;
}
