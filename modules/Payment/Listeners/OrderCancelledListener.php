<?php

declare(strict_types=1);

namespace Modules\Payment\Listeners;

use Modules\Order\Events\OrderCancelled;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Jobs\ProcessPaymentVoidJob;
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

        $reason = $event->reason ?? 'Order cancelled';

        // 1. Pending: safe local cancellation immediately (zero remote gateway calls)
        if ($payment->status === PaymentStatus::PENDING->value) {
            $this->cancellationService->cancel(
                tenantId: $payment->tenant_id,
                paymentUuid: $payment->uuid,
                reason: $reason
            );

            return;
        }

        // 2. Captured / partially refunded: mark reconciliation required (zero remote gateway calls)
        if ($payment->status === PaymentStatus::CAPTURED->value || $payment->status === PaymentStatus::PARTIALLY_REFUNDED->value) {
            $this->cancellationService->cancel(
                tenantId: $payment->tenant_id,
                paymentUuid: $payment->uuid,
                reason: $reason
            );

            return;
        }

        // 3. Authorized: DO NOT call gateway void inline! Enqueue out-of-band void job
        if ($payment->status === PaymentStatus::AUTHORIZED->value) {
            ProcessPaymentVoidJob::dispatch(
                $payment->tenant_id,
                $payment->uuid,
                $reason
            );
        }
    }
}
