<?php

declare(strict_types=1);

namespace Modules\Ledger\Listeners;

use Carbon\CarbonImmutable;
use Modules\Ledger\DTOs\PaymentFinancialMovementDTO;
use Modules\Ledger\Jobs\PostPaymentFinancialMovementJob;
use Modules\Ledger\Policies\PaymentMovementEligibilityPolicy;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;

class PaymentEventAdapter
{
    public function __construct(
        private readonly PaymentMovementEligibilityPolicy $eligibilityPolicy
    ) {}

    public function handle(PaymentCaptured|PaymentPartiallyRefunded|PaymentRefunded $event): void
    {
        /** @var Payment $payment */
        $payment = $event->payment;
        /** @var PaymentTransaction $transaction */
        $transaction = $event->transaction;

        // 1. Fail-closed semantic validation
        if ($event instanceof PaymentCaptured) {
            if ($transaction->status !== 'success') {
                return;
            }

            if (! in_array($transaction->operation_type, ['purchase', 'capture', 'zero_total_settlement'], true)) {
                return;
            }

            // Zero-total settlement or non-positive amount is a deterministic financial no-op
            if ($transaction->operation_type === 'zero_total_settlement' || $transaction->amount_minor <= 0) {
                return;
            }
        } else {
            if ($transaction->status !== 'success') {
                return;
            }

            if ($transaction->operation_type !== 'refund') {
                return;
            }

            if ($transaction->amount_minor <= 0) {
                return;
            }
        }

        if (! $this->eligibilityPolicy->isEligible($transaction->operation_type, $transaction->status, $transaction->amount_minor)) {
            return;
        }

        // 2. Authoritative occurrence time bridge from transaction updated_at in UTC
        $occurredAtUtc = CarbonImmutable::instance($transaction->updated_at)->utc();

        $orderUuid = $payment->order !== null ? (string) $payment->order->uuid : null;

        // 3. Snapshot into pure scalar immutable DTO
        $dto = new PaymentFinancialMovementDTO(
            tenantId: (int) $transaction->tenant_id,
            paymentUuid: (string) $payment->uuid,
            transactionUuid: (string) $transaction->uuid,
            operationType: (string) $transaction->operation_type,
            amountMinor: (int) $transaction->amount_minor,
            currency: (string) $transaction->currency,
            occurredAtUtc: $occurredAtUtc,
            orderUuid: $orderUuid
        );

        // 4. Dispatch the single ShouldQueue posting job
        PostPaymentFinancialMovementJob::dispatch($dto);
    }
}
