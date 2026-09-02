<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class GatewayUnavailableException extends RuntimeException
{
    public static function forProvider(string $provider): self
    {
        return new self("Payment gateway provider [{$provider}] is unavailable or not registered.");
    }
}
