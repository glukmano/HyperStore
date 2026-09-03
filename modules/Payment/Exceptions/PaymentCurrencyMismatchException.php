<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class PaymentCurrencyMismatchException extends RuntimeException
{
    public static function forMismatch(string $requested, string $expected): self
    {
        return new self("Requested currency {$requested} does not match order currency {$expected}.");
    }

    public static function forCurrencies(string $requested, string $expected): self
    {
        return self::forMismatch($requested, $expected);
    }
}
