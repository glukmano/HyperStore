<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Order\Models\Order;
use Modules\Order\Models\SellerOrder;

interface MasterOrderSplitServiceInterface
{
    /**
     * Splits an authoritative Order into deterministic, financially conserved SellerOrders.
     *
     * @return Collection<int, SellerOrder>
     */
    public function splitOrder(Order $order): Collection;
}
