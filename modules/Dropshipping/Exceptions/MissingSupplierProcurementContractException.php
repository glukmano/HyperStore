<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Exceptions;

use DomainException;

class MissingSupplierProcurementContractException extends DomainException
{
    public static function forItem(int $orderItemId, string $sku, int $supplierId, string $reason): self
    {
        return new self("Authoritative supplier procurement contract missing for OrderItem [{$orderItemId}], SKU [{$sku}], Supplier [{$supplierId}]: {$reason}. Fail closed.");
    }
}
