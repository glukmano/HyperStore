<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use App\Core\Tenancy\Models\Tenant;

interface TenantLifecycleServiceInterface
{
    public function activate(int $tenantId): Tenant;

    public function suspend(int $tenantId, string $reason): Tenant;

    public function reactivate(int $tenantId): Tenant;

    public function terminate(int $tenantId, string $reason): Tenant;
}
