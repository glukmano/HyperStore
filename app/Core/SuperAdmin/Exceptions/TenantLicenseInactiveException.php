<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class TenantLicenseInactiveException extends RuntimeException
{
    public static function forTenant(int $tenantId, string $status): self
    {
        return new self("Tenant [{$tenantId}] license is {$status}; application access is suspended.");
    }
}
