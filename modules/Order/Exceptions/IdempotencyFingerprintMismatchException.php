<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use RuntimeException;

class IdempotencyFingerprintMismatchException extends RuntimeException
{
    public static function forOperation(string $operationType, string $idempotencyKey): self
    {
        return new self("Idempotency key [{$idempotencyKey}] for operation [{$operationType}] was previously executed with a different request payload.", 422);
    }
}
