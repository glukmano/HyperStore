<?php

declare(strict_types=1);

namespace Modules\Customers\Listeners;

use Modules\Customers\Services\CustomerReferralService;
use Modules\Order\Events\OrderStatusChanged;

final class QualifyCustomerReferralOnOrderPaidListener
{
    public function __construct(
        private readonly CustomerReferralService $referralService,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->dimension !== 'payment' || $event->toStatus !== 'paid') {
            return;
        }

        $this->referralService->qualifyOnOrderPaid($event->order);
    }
}
