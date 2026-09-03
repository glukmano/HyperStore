<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use DomainException;

class MissingHistoricalCommercialModelException extends DomainException
{
    public static function forOrder(int $orderId): self
    {
        return new self("ORDER_HISTORICAL_COMMERCIAL_MODEL_MISSING: Order [{$orderId}] does not have an authoritative commercial_model_snapshot. Cannot split or materialize SellerOrders.");
    }
}
