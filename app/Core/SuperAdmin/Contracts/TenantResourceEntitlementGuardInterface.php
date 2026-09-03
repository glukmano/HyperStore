<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

interface TenantResourceEntitlementGuardInterface
{
    /**
     * @template T
     *
     * @param  callable(): T  $mutation
     * @return T
     */
    public function admit(int $tenantId, string $resourceKey, callable $mutation): mixed;
}
