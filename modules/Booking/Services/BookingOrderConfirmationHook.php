<?php

declare(strict_types=1);

namespace Modules\Booking\Services;

use Modules\Booking\Contracts\BookingOrderConfirmationHookInterface;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Models\Order;

final class BookingOrderConfirmationHook implements BookingOrderConfirmationHookInterface
{
    public function __construct(
        private readonly BookingHoldService $holdService,
    ) {}

    public function confirmFromOrder(Order $order): void
    {
        $checkout = CheckoutSession::find($order->checkout_id);
        if ($checkout === null) {
            return;
        }

        $this->holdService->confirmForCheckoutSession((string) $checkout->uuid, (int) $order->id);
    }
}
