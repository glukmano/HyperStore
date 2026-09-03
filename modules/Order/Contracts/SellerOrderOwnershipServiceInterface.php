<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\Models\SellerOrder;

interface SellerOrderOwnershipServiceInterface
{
    public function verifyOwnership(SellerOrder $sellerOrder, ?int $vendorId = null): void;
}
