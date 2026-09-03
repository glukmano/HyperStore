<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\ContextualMutationAuthorizerInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\Tenancy\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Models\VendorUser;

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

    public function executeStoreAuthorized(int $tenantId, int $storeId, int $userId, string $requiredRole, callable $mutation): mixed
    {
        return DB::transaction(function () use ($tenantId, $storeId, $userId, $requiredRole, $mutation) {
            /** @var ?Store $store */
            $store = Store::where('tenant_id', $tenantId)->find($storeId);
            if ($store === null) {
                throw UnauthorizedContextException::invalidContext("Store [{$storeId}] does not belong to Tenant [{$tenantId}].");
            }

            return $this->executeTenantAuthorized($tenantId, $userId, $requiredRole, $mutation);
        });
    }

    public function executeVendorAuthorized(int $tenantId, int $vendorId, int $userId, string $requiredRole, callable $mutation): mixed
    {
        return DB::transaction(function () use ($tenantId, $vendorId, $userId, $requiredRole, $mutation) {
            /** @var ?VendorUser $membership */
            $membership = VendorUser::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($membership === null || ! $membership->is_active) {
                throw UnauthorizedContextException::invalidContext("Active VendorUser membership required for Vendor [{$vendorId}].");
            }

            $currentRole = $membership->role instanceof VendorRole ? $membership->role->value : (string) $membership->role;
            if ($requiredRole !== '' && $currentRole !== $requiredRole && $currentRole !== VendorRole::Owner->value && $currentRole !== VendorRole::Manager->value) {
                throw UnauthorizedContextException::invalidContext("Vendor role [{$currentRole}] does not satisfy required role [{$requiredRole}].");
            }

            return $mutation();
        });
    }

    public function executeSuperAdminAuthorized(int $userId, callable $mutation): mixed
    {
        return DB::transaction(function () use ($userId, $mutation) {
            /** @var ?User $user */
            $user = User::where('id', $userId)->lockForUpdate()->first();

            if ($user === null || ! $user->isSuperAdmin() || $user->status !== 'active') {
                throw UnauthorizedContextException::invalidContext('Super Admin privileges required.');
            }

            return $mutation();
        });
    }
}
