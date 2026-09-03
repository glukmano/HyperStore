<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class PrivilegedActionBlockedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $action = ''
    ) {
        parent::__construct($message);
    }

    public static function blocked(string $action): self
    {
        return new self("Action [{$action}] is prohibited while operating under an impersonated session.", $action);
    }
}
