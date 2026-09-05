<?php

declare(strict_types=1);

namespace Modules\Promotions\Listeners;

use Modules\Order\Events\OrderStatusChanged;
use Modules\Promotions\Services\LoyaltyService;

final class EarnLoyaltyPointsOnOrderPaidListener
{
    public function __construct(
        private readonly LoyaltyService $loyaltyService,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->dimension !== 'payment' || $event->toStatus !== 'paid') {
            return;
        }

        $this->loyaltyService->earnFromOrder($event->order);
    }
}
