<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class PaymentAmountMismatchException extends RuntimeException
{
    public static function forMismatch(int $requested, int $expected): self
    {
        return new self("Requested payment amount {$requested} does not match order grand total {$expected}.");
    }

    public static function forAmounts(int $requested, int $expected): self
    {
        return self::forMismatch($requested, $expected);
    }
}
