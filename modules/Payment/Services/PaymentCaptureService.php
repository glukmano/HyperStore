<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\Contracts\PaymentConcurrencyBarrierInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Contracts\PaymentIdempotencyServiceInterface;
use Modules\Payment\DTOs\GatewayCaptureRequest;
use Modules\Payment\Enums\PaymentOperationType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Exceptions\InvalidPaymentTransitionException;
use Modules\Payment\Exceptions\OverCaptureException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;

class PaymentCaptureService
{
    public function __construct(
        private readonly PaymentIdempotencyServiceInterface $idempotencyService,
        private readonly PaymentGatewayRegistryInterface $gatewayRegistry,
        private readonly OrderPaymentSynchronizationServiceInterface $orderPaymentSyncService,
        private readonly PaymentConcurrencyBarrierInterface $concurrencyBarrier
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function capture(
        int $tenantId,
        string $paymentUuid,
        int $amountMinor,
        ?string $idempotencyKey = null,
        array $metadata = []
    ): array {
        /** @var Payment $payment */
        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('uuid', $paymentUuid)
            ->firstOrFail();

        $payload = [
            'tenant_id' => $tenantId,
            'payment_uuid' => $paymentUuid,
            'amount_minor' => $amountMinor,
            'metadata' => $metadata,
        ];

        return $this->idempotencyService->execute(
            tenantId: $tenantId,
            orderId: $payment->order_id,
            paymentId: $payment->id,
            operationType: 'capture_payment',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function (PaymentOperationKey $opKey) use ($payment, $amountMinor, $metadata): array {
                return $this->executeCapture($payment, $amountMinor, $opKey, $metadata);
            }
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function executeCapture(
        Payment $payment,
        int $amountMinor,
        PaymentOperationKey $opKey,
        array $metadata
    ): array {
        // Step 1: Pre-call verification and record creation under row lock
        [$lockedPayment, $transaction, $originalTx] = DB::transaction(function () use ($payment, $amountMinor, $opKey): array {
            /** @var Payment $locked */
            $locked = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::AUTHORIZED->value) {
                throw InvalidPaymentTransitionException::forTransition($locked->status, 'captured');
            }

            $remainingCapturable = min($locked->authorized_amount_minor, $locked->amount_minor) - $locked->captured_amount_minor;

            if ($amountMinor > $remainingCapturable) {
                throw OverCaptureException::forAmount($amountMinor, $remainingCapturable);
            }

            /** @var PaymentTransaction|null $existingTx */
            $existingTx = PaymentTransaction::query()
                ->where('payment_operation_key_id', $opKey->id)
                ->first();

            if ($existingTx !== null) {
                return [$locked, $existingTx, null];
            }

            /** @var PaymentTransaction|null $authTx */
            $authTx = PaymentTransaction::query()
                ->where('payment_id', $locked->id)
                ->where('operation_type', PaymentOperationType::AUTHORIZE->value)
                ->where('status', PaymentTransactionStatus::SUCCESS->value)
                ->latest('id')
                ->first();

            $providerCode = $authTx->provider_code ?? 'fake';
            $providerIdempotencyKey = "hyp_tx_{$locked->tenant_id}_{$locked->order_id}_{$opKey->id}";

            $transaction = PaymentTransaction::create([
                'tenant_id' => $locked->tenant_id,
                'payment_id' => $locked->id,
                'payment_operation_key_id' => $opKey->id,
                'operation_type' => PaymentOperationType::CAPTURE->value,
                'status' => PaymentTransactionStatus::PENDING->value,
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'provider_code' => $providerCode,
                'provider_reference' => $authTx?->provider_reference,
                'provider_idempotency_key' => $providerIdempotencyKey,
            ]);

            return [$locked, $transaction, $authTx];
        });

        if ($transaction->status === PaymentTransactionStatus::SUCCESS->value) {
            $refreshed = $lockedPayment->fresh();
            if ($refreshed === null) {
                throw new \RuntimeException('Payment not found');
            }

            return $this->formatResponse($refreshed, $transaction);
        }

        $this->concurrencyBarrier->wait('after_capture_pre_call_commit');

        // Step 2: Remote gateway capture outside DB transaction
        $providerCode = $transaction->provider_code ?? 'fake';
        $gateway = $this->gatewayRegistry->get($providerCode);

        $request = new GatewayCaptureRequest(
            tenantId: $lockedPayment->tenant_id,
            paymentId: $lockedPayment->id,
            transactionId: $transaction->id,
            amountMinor: $amountMinor,
            currency: $lockedPayment->currency,
            providerReference: $transaction->provider_reference,
            providerIdempotencyKey: (string) $transaction->provider_idempotency_key,
            metadata: $metadata
        );

        try {
            $result = $gateway->capture($request);
        } catch (Exception $e) {
            DB::transaction(function () use ($transaction, $e): void {
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();
                $lockedTx->status = PaymentTransactionStatus::UNKNOWN->value;
                $lockedTx->normalized_error_code = 'timeout';
                $lockedTx->action_payload = ['error' => $e->getMessage()];
                $lockedTx->save();
            });

            throw PaymentReconciliationPendingException::forTransaction($transaction->id);
        }

        // Step 3: Post-call DB settlement under row lock
        return DB::transaction(function () use ($lockedPayment, $transaction, $result, $amountMinor): array {
            /** @var Payment $finalPayment */
            $finalPayment = Payment::query()->where('id', $lockedPayment->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentTransaction $finalTx */
            $finalTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            if ($result->status === PaymentTransactionStatus::SUCCESS) {
                $finalTx->status = PaymentTransactionStatus::SUCCESS->value;
                $finalTx->provider_reference = $result->providerReference;
                $finalTx->save();

                $finalPayment->captured_amount_minor += $amountMinor;

                if ($finalPayment->captured_amount_minor === $finalPayment->amount_minor) {
                    $finalPayment->status = PaymentStatus::CAPTURED->value;
                    $finalPayment->captured_at = now();
                    $finalPayment->save();

                    $this->orderPaymentSyncService->syncPaymentStatus(
                        tenantId: $finalPayment->tenant_id,
                        orderId: $finalPayment->order_id,
                        status: OrderPaymentStatus::PAID,
                        reason: 'Payment fully captured'
                    );
                } else {
                    // Partial capture: Payment stays AUTHORIZED, Order payment_status stays AUTHORIZED
                    $finalPayment->save();
                }

                DB::afterCommit(function () use ($finalPayment, $finalTx): void {
                    PaymentCaptured::dispatch($finalPayment, $finalTx);
                });
            } else {
                $finalTx->status = PaymentTransactionStatus::FAILURE->value;
                $finalTx->normalized_error_code = $result->normalizedErrorCode ?? 'capture_declined';
                $finalTx->save();
            }

            return $this->formatResponse($finalPayment, $finalTx);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function formatResponse(Payment $payment, PaymentTransaction $transaction): array
    {
        return [
            'payment_id' => $payment->id,
            'payment_uuid' => $payment->uuid,
            'status' => $payment->status,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'authorized_amount_minor' => $payment->authorized_amount_minor,
            'captured_amount_minor' => $payment->captured_amount_minor,
            'refunded_amount_minor' => $payment->refunded_amount_minor,
            'transaction_id' => $transaction->id,
            'transaction_status' => $transaction->status,
            'provider_reference' => $transaction->provider_reference,
            'normalized_error_code' => $transaction->normalized_error_code,
        ];
    }
}
