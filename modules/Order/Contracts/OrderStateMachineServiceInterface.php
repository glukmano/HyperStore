<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\DTOs\OrderTransitionDTO;
use Modules\Order\Models\Order;

interface OrderStateMachineServiceInterface
{
    /**
     * Transitions an order to a new status across a given dimension (order, payment, fulfillment),
     * ensuring transition validity and recording status history.
     */
    public function transition(Order $order, OrderTransitionDTO $transition): Order;
}
