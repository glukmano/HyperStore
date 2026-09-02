<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class OrderAlreadyCancelledException extends RuntimeException
{
    public static function forOrder(int $orderId): self
    {
        return new self("Order {$orderId} is already cancelled. Payment cannot be initiated.");
    }
}
