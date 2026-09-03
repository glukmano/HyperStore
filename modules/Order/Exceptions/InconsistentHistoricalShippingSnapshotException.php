<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use DomainException;

class InconsistentHistoricalShippingSnapshotException extends DomainException
{
    public static function arithmeticMismatch(int $orderId, string $reason): self
    {
        return new self("HISTORICAL_SHIPPING_SNAPSHOT_INCONSISTENT: Order [{$orderId}] shipping snapshot failed validation: {$reason}");
    }
}
