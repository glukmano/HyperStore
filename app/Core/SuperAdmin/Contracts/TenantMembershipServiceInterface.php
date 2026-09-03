<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use App\Core\Tenancy\Models\TenantUser;

interface TenantMembershipServiceInterface
{
    public function revokeMembership(int $tenantId, int $targetUserId, ?int $actorUserId = null): TenantUser;
}
