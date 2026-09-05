<?php

declare(strict_types=1);

namespace Modules\Promotions\Listeners;

use Modules\Order\Models\Order;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;
use Modules\Promotions\Services\LoyaltyService;

final class ReverseLoyaltyPointsOnRefundListener
{
    public function __construct(
        private readonly LoyaltyService $loyaltyService,
    ) {}

    public function handle(PaymentRefunded|PaymentPartiallyRefunded $event): void
    {
        $order = Order::where('tenant_id', $event->payment->tenant_id)->find($event->payment->order_id);
        if ($order === null) {
            return;
        }

        $this->loyaltyService->reverseForOrderRefund($order);
    }
}
