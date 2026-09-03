<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class GatewayIndeterminateOutcomeException extends RuntimeException
{
    public static function timeout(?string $message = null): self
    {
        return new self($message ?? 'Payment gateway outcome is indeterminate due to network timeout or disconnect.');
    }
}
