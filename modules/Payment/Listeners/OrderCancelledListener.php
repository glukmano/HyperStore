<?php

declare(strict_types=1);

namespace Modules\Payment\Listeners;

use Modules\Order\Events\OrderCancelled;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentCancellationService;

class OrderCancelledListener
{
    public function __construct(
        private readonly PaymentCancellationService $cancellationService
    ) {}

    public function handle(OrderCancelled $event): void
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('tenant_id', $event->order->tenant_id)
            ->where('order_id', $event->order->id)
            ->first();

        if ($payment === null) {
            return;
        }

        $this->cancellationService->cancel(
            tenantId: $payment->tenant_id,
            paymentUuid: $payment->uuid,
            reason: $event->reason ?? 'Order cancelled'
        );
    }
}
