<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class TenantSuspendedException extends RuntimeException
{
    public static function forTenant(int $tenantId, string $status): self
    {
        return new self("Tenant [{$tenantId}] is {$status}; administrative and application access is denied.");
    }
}
