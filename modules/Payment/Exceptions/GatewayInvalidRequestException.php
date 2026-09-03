<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class GatewayInvalidRequestException extends RuntimeException
{
    public static function invalid(string $reason): self
    {
        return new self("Payment gateway rejected request as invalid: {$reason}");
    }
}
