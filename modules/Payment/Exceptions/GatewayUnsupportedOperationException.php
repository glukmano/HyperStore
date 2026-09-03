<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class GatewayUnsupportedOperationException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self("Payment gateway does not support operation: {$operation}");
    }
}
