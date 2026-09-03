<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class SuperAdminImpersonationForbiddenException extends RuntimeException
{
    public static function attempted(int $targetUserId): self
    {
        return new self("Impersonating a Super Admin (user [{$targetUserId}]) is strictly forbidden.");
    }
}
