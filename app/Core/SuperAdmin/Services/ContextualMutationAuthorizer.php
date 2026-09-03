<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\ContextualMutationAuthorizerInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\Tenancy\Models\TenantUser;
use Illuminate\Support\Facades\DB;

final readonly class ContextualMutationAuthorizer implements ContextualMutationAuthorizerInterface
{
    public function executeTenantAuthorized(int $tenantId, int $userId, string $requiredRole, callable $mutation): mixed
    {
        return DB::transaction(function () use ($tenantId, $userId, $requiredRole, $mutation) {
            /** @var ?TenantUser $membership */
            $membership = TenantUser::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($membership === null || ! $membership->is_active) {
                throw UnauthorizedContextException::invalidContext("Active membership required for Tenant [{$tenantId}].");
            }

            if ($requiredRole !== '' && $membership->role !== $requiredRole && $membership->role !== 'admin' && $membership->role !== 'owner') {
                throw UnauthorizedContextException::invalidContext("Role [{$membership->role}] does not satisfy required role [{$requiredRole}].");
            }

            return $mutation();
        });
    }
}
