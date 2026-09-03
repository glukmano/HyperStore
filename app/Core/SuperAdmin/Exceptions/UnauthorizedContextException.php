<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class UnauthorizedContextException extends RuntimeException
{
    public static function unauthenticated(): self
    {
        return new self('Unauthenticated: Access to Control Center requires valid authentication.');
    }

    public static function invalidContext(string $reason): self
    {
        return new self("Unauthorized context access: {$reason}");
    }
}
