<?php

declare(strict_types=1);

namespace Modules\B2B\Services;

use Modules\B2B\Enums\CompanyUserRole;
use Modules\B2B\Exceptions\CompanyAuthorizationException;
use Modules\B2B\Models\CompanyUser;

/**
 * Owner Delta §2: the ONE place that decides Company-local purchasing
 * authority. Always resolves the authenticated User's OWN CompanyUser row
 * scoped to the SPECIFIC target Company — never a bare global permission
 * check. A User's role in a different Company confers zero authority here.
 */
final class CompanyAuthorizationService
{
    public function roleFor(int $tenantId, int $companyId, int $userId): ?CompanyUserRole
    {
        /** @var CompanyUser|null $companyUser */
        $companyUser = CompanyUser::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        return $companyUser?->role;
    }

    /**
     * @param  list<CompanyUserRole>  $allowedRoles
     */
    public function assertRole(int $tenantId, int $companyId, int $userId, array $allowedRoles, string $action): void
    {
        $role = $this->roleFor($tenantId, $companyId, $userId);

        if ($role === null || ! in_array($role, $allowedRoles, true)) {
            throw CompanyAuthorizationException::forAction($action, $companyId);
        }
    }
}
