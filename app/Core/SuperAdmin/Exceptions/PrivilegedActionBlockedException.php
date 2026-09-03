<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class PrivilegedActionBlockedException extends RuntimeException
{
    public static function blocked(string $action): self
    {
        return new self("Action [{$action}] is prohibited while operating under an impersonated session.");
    }
}
