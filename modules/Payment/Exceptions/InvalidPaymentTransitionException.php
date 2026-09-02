<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class InvalidPaymentTransitionException extends RuntimeException
{
    public static function forTransition(string $from, string $to): self
    {
        return new self("Invalid payment transition from [{$from}] to [{$to}].");
    }
}
