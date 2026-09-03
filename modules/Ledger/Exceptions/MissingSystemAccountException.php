<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

class MissingSystemAccountException extends RuntimeException
{
    public static function forRole(int $tenantId, string $role): self
    {
        return new self("Required system account role [{$role}] is not configured or active for tenant [{$tenantId}].");
    }
}
