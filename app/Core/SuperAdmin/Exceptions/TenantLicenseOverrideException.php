<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class TenantLicenseOverrideException extends RuntimeException
{
    public static function limitBelowCurrentUsage(int $tenantId, string $resourceKey, int $currentUsage, int $newEffectiveLimit): self
    {
        return new self("Cannot set or remove override for [{$resourceKey}] resulting in effective limit [{$newEffectiveLimit}]: Tenant [{$tenantId}] current usage is [{$currentUsage}].");
    }
}
