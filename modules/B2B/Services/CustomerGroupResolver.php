<?php

declare(strict_types=1);

namespace Modules\B2B\Services;

use Modules\B2B\Contracts\CustomerGroupResolverInterface;
use Modules\B2B\Enums\CompanyStatus;
use Modules\B2B\Models\CompanyUser;

final class CustomerGroupResolver implements CustomerGroupResolverInterface
{
    public function resolveForUser(int $tenantId, ?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        /** @var CompanyUser|null $companyUser */
        $companyUser = CompanyUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with('company')
            ->first();

        if ($companyUser === null || $companyUser->company === null || $companyUser->company->status !== CompanyStatus::Active) {
            return null;
        }

        return $companyUser->company->customer_group_id;
    }
}
