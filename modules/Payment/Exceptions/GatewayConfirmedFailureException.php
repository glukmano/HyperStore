<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class GatewayConfirmedFailureException extends RuntimeException
{
    public static function declined(string $reason = 'declined'): self
    {
        return new self("Payment gateway confirmed failure: {$reason}");
    }
}
