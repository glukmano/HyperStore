<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use RuntimeException;

class OrderNotFoundException extends RuntimeException
{
    public static function withIdentifier(string $identifier): self
    {
        return new self("Order [{$identifier}] not found.", 404);
    }
}
