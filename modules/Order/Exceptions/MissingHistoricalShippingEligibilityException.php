<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use DomainException;

class MissingHistoricalShippingEligibilityException extends DomainException
{
    public static function forOrderItem(int $orderItemId): self
    {
        return new self("ORDER_ITEM_SHIPPING_ELIGIBILITY_MISSING: OrderItem [{$orderItemId}] does not have an authoritative requires_shipping_snapshot. Cannot allocate shipping.");
    }
}
