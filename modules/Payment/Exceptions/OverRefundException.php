<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class OverRefundException extends RuntimeException
{
    public static function forAmount(int $requested, int $remaining): self
    {
        return new self("Refund amount {$requested} exceeds remaining refundable amount {$remaining}.");
    }
}
