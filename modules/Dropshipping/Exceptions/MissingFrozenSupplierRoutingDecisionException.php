<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Exceptions;

use DomainException;

class MissingFrozenSupplierRoutingDecisionException extends DomainException
{
    public static function forFulfillment(int $fulfillmentId, string $reason): self
    {
        return new self("Automatic dropship PurchaseOrder creation requires a frozen SupplierRoutingEngine decision for OrderFulfillment [{$fulfillmentId}]: {$reason}. Fail closed.");
    }

    public static function forItem(int $fulfillmentId, int $orderItemId, string $reason): self
    {
        return new self("Automatic dropship PurchaseOrder creation requires a frozen routing decision for OrderFulfillment [{$fulfillmentId}], OrderItem [{$orderItemId}]: {$reason}. Fail closed.");
    }
}
