<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class MissingEntitlementConfigurationException extends RuntimeException
{
    public static function forResource(int $tenantId, string $resourceKey): self
    {
        return new self("No authoritative hard limit configured for resource [{$resourceKey}] under Tenant [{$tenantId}]. Fail-closed policy enforced.");
    }
}
