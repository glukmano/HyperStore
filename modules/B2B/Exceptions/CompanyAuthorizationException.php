<?php

declare(strict_types=1);

namespace Modules\B2B\Exceptions;

/**
 * Owner Delta §2: thrown whenever a User attempts a Company-scoped action
 * without holding the required CompanyUser role for THAT SPECIFIC Company.
 */
final class CompanyAuthorizationException extends B2BException
{
    public static function forAction(string $action, int $companyId): self
    {
        return new self("User is not authorized to perform [{$action}] for Company [{$companyId}].");
    }
}
