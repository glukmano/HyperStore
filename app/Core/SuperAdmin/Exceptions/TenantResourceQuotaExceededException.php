<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class TenantResourceQuotaExceededException extends RuntimeException
{
    public static function limitReached(int $tenantId, string $resourceKey, int $currentUsage, int $effectiveLimit): self
    {
        return new self("Tenant [{$tenantId}] has reached the hard quota for [{$resourceKey}]: current usage [{$currentUsage}], effective limit [{$effectiveLimit}].");
    }
}
