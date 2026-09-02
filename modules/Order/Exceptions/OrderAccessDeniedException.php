<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use RuntimeException;

class OrderAccessDeniedException extends RuntimeException
{
    public static function denied(): self
    {
        return new self('Access denied to requested order.', 403);
    }
}
