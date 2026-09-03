<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class ImpersonationSessionTerminatedException extends RuntimeException
{
    public static function alreadyTerminated(string $sessionUuid): self
    {
        return new self("Impersonation session [{$sessionUuid}] is already terminated.");
    }
}
