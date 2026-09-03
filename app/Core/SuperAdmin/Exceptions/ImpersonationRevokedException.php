<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class ImpersonationRevokedException extends RuntimeException
{
    public static function sessionRevoked(string $sessionUuid): self
    {
        return new self("Impersonation session [{$sessionUuid}] has been revoked or expired.");
    }
}
