<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\Contracts\PaymentConcurrencyBarrierInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Contracts\PaymentIdempotencyServiceInterface;
use Modules\Payment\DTOs\GatewayRefundRequest;
use Modules\Payment\Enums\PaymentOperationType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;
use Modules\Payment\Exceptions\GatewayIndeterminateOutcomeException;
use Modules\Payment\Exceptions\GatewayUnavailableException;
use Modules\Payment\Exceptions\InvalidPaymentTransitionException;
use Modules\Payment\Exceptions\OverRefundException;
use Modules\Payment\Exceptions\PaymentNotFoundException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;
use Throwable;

class PaymentRefundService
{
    public function __construct(
        private readonly PaymentIdempotencyServiceInterface $idempotencyService,
        private readonly PaymentGatewayRegistryInterface $gatewayRegistry,
        private readonly OrderPaymentSynchronizationServiceInterface $orderPaymentSyncService,
        private readonly PaymentConcurrencyBarrierInterface $concurrencyBarrier,
        private readonly PaymentTransactionReconciliationService $reconciliationService
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function refund(
        int $tenantId,
        string $paymentUuid,
        int $amountMinor,
        ?string $idempotencyKey = null,
        array $metadata = []
    ): array {
        /** @var Payment|null $payment */
        $payment = Payment::query()->where('tenant_id', $tenantId)->where('uuid', $paymentUuid)->first();

        if ($payment === null) {
            throw PaymentNotFoundException::forUuid($paymentUuid);
        }

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
            operationType: 'refund',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: fn (PaymentOperationKey $opKey): array => $this->executeRefund($payment, $amountMinor, $opKey, $metadata)
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function executeRefund(
        Payment $payment,
        int $amountMinor,
        PaymentOperationKey $opKey,
        array $metadata
    ): array {
        // Step 1: Pre-call verification under row lock
        [$lockedPayment, $transaction] = DB::transaction(function () use ($payment, $amountMinor, $opKey): array {
            /** @var Payment $locked */
            $locked = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PaymentStatus::CAPTURED->value, PaymentStatus::PARTIALLY_REFUNDED->value], true)) {
                throw InvalidPaymentTransitionException::forTransition($locked->status, 'refunded');
            }

            $remainingRefundable = $locked->captured_amount_minor - $locked->refunded_amount_minor;
            if ($amountMinor > $remainingRefundable) {
                throw OverRefundException::forAmounts($amountMinor, $remainingRefundable);
            }

            /** @var PaymentTransaction|null $existingTx */
            $existingTx = PaymentTransaction::query()
                ->where('payment_operation_key_id', $opKey->id)
                ->first();

            if ($existingTx !== null) {
                return [$locked, $existingTx];
            }

            /** @var PaymentTransaction|null $captureTx */
            $captureTx = PaymentTransaction::query()
                ->where('payment_id', $locked->id)
                ->whereIn('operation_type', [PaymentOperationType::PURCHASE->value, PaymentOperationType::CAPTURE->value])
                ->where('status', PaymentTransactionStatus::SUCCESS->value)
                ->latest('id')
                ->first();

            $providerCode = $captureTx?->provider_code;
            if ($providerCode === null) {
                if (! $this->gatewayRegistry->hasDefault()) {
                    throw GatewayUnavailableException::forProvider('default');
                }
                $providerCode = $this->gatewayRegistry->default()->getProviderCode();
            }
            $providerIdempotencyKey = "hyp_tx_{$locked->tenant_id}_{$locked->order_id}_{$opKey->id}";

            $transaction = PaymentTransaction::create([
                'tenant_id' => $locked->tenant_id,
                'payment_id' => $locked->id,
                'payment_operation_key_id' => $opKey->id,
                'operation_type' => PaymentOperationType::REFUND->value,
                'status' => PaymentTransactionStatus::PENDING->value,
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'provider_code' => $providerCode,
                'provider_reference' => $captureTx?->provider_reference,
                'provider_idempotency_key' => $providerIdempotencyKey,
            ]);

            return [$locked, $transaction];
        });

        // Replay completed transaction
        if ($transaction->status === PaymentTransactionStatus::SUCCESS->value) {
            return $this->reconciliationService->formatResponse($lockedPayment->fresh() ?? $lockedPayment, $transaction);
        }

        // Reconcile indeterminate transaction
        if ($transaction->status === PaymentTransactionStatus::UNKNOWN->value) {
            return $this->reconciliationService->reconcile($transaction, $lockedPayment, $opKey);
        }

        $this->concurrencyBarrier->wait('after_refund_pre_call_commit');

        // Remote gateway refund outside DB transaction
        $providerCode = $transaction->provider_code;
        if ($providerCode === null) {
            throw GatewayUnavailableException::forProvider('unknown');
        }
        $gateway = $this->gatewayRegistry->get($providerCode);

        $request = new GatewayRefundRequest(
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
            $result = $gateway->refund($request);
        } catch (GatewayIndeterminateOutcomeException) {
            DB::transaction(function () use ($transaction): void {
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();
                $lockedTx->status = PaymentTransactionStatus::UNKNOWN->value;
                $lockedTx->normalized_error_code = 'gateway_timeout';
                $lockedTx->action_payload = null;
                $lockedTx->save();
            });

            throw PaymentReconciliationPendingException::forTransaction($transaction->id);
        } catch (Throwable) {
            return DB::transaction(function () use ($lockedPayment, $transaction): array {
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();
                $lockedTx->status = PaymentTransactionStatus::FAILURE->value;
                $lockedTx->normalized_error_code = 'gateway_error';
                $lockedTx->action_payload = null;
                $lockedTx->save();

                return $this->reconciliationService->formatResponse($lockedPayment->fresh() ?? $lockedPayment, $lockedTx);
            });
        }

        return DB::transaction(function () use ($lockedPayment, $transaction, $result, $amountMinor): array {
            /** @var Payment $finalPayment */
            $finalPayment = Payment::query()->where('id', $lockedPayment->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentTransaction $finalTx */
            $finalTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            if ($result->status === PaymentTransactionStatus::SUCCESS) {
                $finalTx->status = PaymentTransactionStatus::SUCCESS->value;
                $finalTx->provider_reference = $result->providerReference;
                $finalTx->normalized_error_code = null;
                $finalTx->action_payload = null;
                $finalTx->save();

                $finalPayment->refunded_amount_minor += $amountMinor;

                if ($finalPayment->refunded_amount_minor === $finalPayment->captured_amount_minor) {
                    $finalPayment->status = PaymentStatus::REFUNDED->value;
                    $finalPayment->save();

                    $this->orderPaymentSyncService->syncPaymentStatus(
                        tenantId: $finalPayment->tenant_id,
                        orderId: $finalPayment->order_id,
                        status: OrderPaymentStatus::REFUNDED,
                        reason: 'Payment fully refunded'
                    );

                    DB::afterCommit(function () use ($finalPayment, $finalTx): void {
                        PaymentRefunded::dispatch($finalPayment, $finalTx);
                    });
                } else {
                    $finalPayment->status = PaymentStatus::PARTIALLY_REFUNDED->value;
                    $finalPayment->save();

                    DB::afterCommit(function () use ($finalPayment, $finalTx): void {
                        PaymentPartiallyRefunded::dispatch($finalPayment, $finalTx);
                    });
                }
            } else {
                $finalTx->status = PaymentTransactionStatus::FAILURE->value;
                $finalTx->normalized_error_code = $result->normalizedErrorCode ?? 'refund_declined';
                $finalTx->action_payload = null;
                $finalTx->save();
            }

            return $this->reconciliationService->formatResponse($finalPayment, $finalTx);
        });
    }
}
