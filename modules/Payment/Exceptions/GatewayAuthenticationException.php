<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class GatewayAuthenticationException extends RuntimeException
{
    public static function failed(?string $message = null): self
    {
        return new self($message ?? 'Payment gateway authentication failed.');
    }
}
