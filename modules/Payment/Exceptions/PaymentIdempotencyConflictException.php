<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class PaymentIdempotencyConflictException extends RuntimeException
{
    public static function forConflict(string $key): self
    {
        return new self("Idempotency key conflict: identical key [{$key}] was submitted with differing request parameters.");
    }
}
