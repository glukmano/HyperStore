<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class NestedImpersonationForbiddenException extends RuntimeException
{
    public static function attempted(): self
    {
        return new self('Nested impersonation is strictly forbidden: cannot start impersonation from an already impersonated session.');
    }
}
