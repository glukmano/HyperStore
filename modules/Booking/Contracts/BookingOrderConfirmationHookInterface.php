<?php

declare(strict_types=1);

namespace Modules\Booking\Contracts;

use Modules\Order\Models\Order;

interface BookingOrderConfirmationHookInterface
{
    public function confirmFromOrder(Order $order): void;
}
