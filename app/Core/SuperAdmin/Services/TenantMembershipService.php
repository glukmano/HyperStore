<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\Context\ContextManager;
use App\Core\SuperAdmin\Contracts\ContextualMutationAuthorizerInterface;
use App\Core\SuperAdmin\Contracts\TenantMembershipServiceInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\Tenancy\Models\TenantUser;
use Illuminate\Support\Facades\DB;

final readonly class TenantMembershipService implements TenantMembershipServiceInterface
{
    public function __construct(
        private ContextualMutationAuthorizerInterface $authorizer,
        private ContextManager $contextManager
    ) {}

    public function revokeMembership(int $tenantId, int $targetUserId, ?int $actorUserId = null): TenantUser
    {
        $actorId = $actorUserId ?? ($this->contextManager->hasUser() ? $this->contextManager->getUser()->getId() : null);

        $mutation = function () use ($tenantId, $targetUserId, $actorId): TenantUser {
            return DB::transaction(function () use ($tenantId, $targetUserId, $actorId): TenantUser {
                /** @var ?TenantUser $membership */
                $membership = TenantUser::where('tenant_id', $tenantId)
                    ->where('user_id', $targetUserId)
                    ->lockForUpdate()
                    ->first();

                if ($membership === null) {
                    throw UnauthorizedContextException::invalidContext("Target user [{$targetUserId}] does not belong to Tenant [{$tenantId}].");
                }

                // Invariant: An admin cannot revoke an owner membership
                if ($membership->role === 'owner' && $actorId !== null) {
                    /** @var ?TenantUser $actorMembership */
                    $actorMembership = TenantUser::where('tenant_id', $tenantId)->where('user_id', $actorId)->first();
                    if ($actorMembership !== null && $actorMembership->role !== 'owner') {
                        throw UnauthorizedContextException::invalidContext('Only a Tenant Owner can revoke an Owner membership.');
                    }
                }

                $membership->is_active = false;
                $membership->save();

                return $membership;
            });
        };

        if ($actorId !== null) {
            return $this->authorizer->executeTenantAuthorized($tenantId, $actorId, 'admin', $mutation);
        }

        return $mutation();
    }
}
