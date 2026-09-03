<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\TenantMembershipServiceInterface;
use App\Core\Tenancy\Models\TenantUser;
use Illuminate\Support\Facades\DB;

final readonly class TenantMembershipService implements TenantMembershipServiceInterface
{
    public function revokeMembership(int $tenantId, int $userId): TenantUser
    {
        return DB::transaction(function () use ($tenantId, $userId): TenantUser {
            /** @var TenantUser $membership */
            $membership = TenantUser::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            $membership->is_active = false;
            $membership->save();

            return $membership;
        });
    }
}
