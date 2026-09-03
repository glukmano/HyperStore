<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\Contracts\PaymentConcurrencyBarrierInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Contracts\PaymentIdempotencyServiceInterface;
use Modules\Payment\DTOs\GatewayVoidRequest;
use Modules\Payment\Enums\PaymentOperationType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Events\PaymentCancelled;
use Modules\Payment\Events\PaymentReconciliationRequired;
use Modules\Payment\Exceptions\GatewayIndeterminateOutcomeException;
use Modules\Payment\Exceptions\InvalidPaymentTransitionException;
use Modules\Payment\Exceptions\PaymentNotFoundException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;
use Throwable;

class PaymentCancellationService
{
    public function __construct(
        private readonly PaymentIdempotencyServiceInterface $idempotencyService,
        private readonly PaymentGatewayRegistryInterface $gatewayRegistry,
        private readonly OrderPaymentSynchronizationServiceInterface $orderPaymentSyncService,
        private readonly PaymentConcurrencyBarrierInterface $concurrencyBarrier,
        private readonly PaymentTransactionReconciliationService $reconciliationService
    ) {}

    /**
     * Cancel/void a payment with state-dependent routing.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function cancel(
        int $tenantId,
        string $paymentUuid,
        string $reason,
        ?string $idempotencyKey = null,
        array $metadata = []
    ): array {
        /** @var Payment|null $payment */
        $payment = Payment::query()->where('tenant_id', $tenantId)->where('uuid', $paymentUuid)->first();

        if ($payment === null) {
            throw PaymentNotFoundException::forUuid($paymentUuid);
        }

        // Branch 1: Pending payment -> cancel locally without gateway call
        if ($payment->status === PaymentStatus::PENDING->value) {
            return $this->cancelPendingLocally($payment, $reason);
        }

        // Branch 2: Captured/partially refunded -> mark reconciliation required without auto-refunding
        if ($payment->status === PaymentStatus::CAPTURED->value || $payment->status === PaymentStatus::PARTIALLY_REFUNDED->value) {
            return $this->markReconciliationRequired($payment, $reason);
        }

        // Branch 3: Authorized payment -> execute remote gateway void idempotently
        if ($payment->status === PaymentStatus::AUTHORIZED->value) {
            $payload = [
                'tenant_id' => $tenantId,
                'payment_uuid' => $paymentUuid,
                'reason' => $reason,
                'metadata' => $metadata,
            ];

            return $this->idempotencyService->execute(
                tenantId: $tenantId,
                orderId: $payment->order_id,
                paymentId: $payment->id,
                operationType: 'void',
                idempotencyKey: $idempotencyKey,
                requestPayload: $payload,
                callback: fn (PaymentOperationKey $opKey): array => $this->executeAuthorizedVoid($payment, $reason, $opKey, $metadata)
            );
        }

        // If already cancelled, return idempotent replay
        if ($payment->status === PaymentStatus::CANCELLED->value) {
            return [
                'payment_uuid' => $payment->uuid,
                'status' => $payment->status,
                'reconciliation_required' => false,
            ];
        }

        throw InvalidPaymentTransitionException::forTransition($payment->status, 'cancelled');
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelPendingLocally(Payment $payment, string $reason): array
    {
        return DB::transaction(function () use ($payment, $reason): array {
            /** @var Payment $locked */
            $locked = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::CANCELLED->value) {
                return [
                    'payment_uuid' => $locked->uuid,
                    'status' => $locked->status,
                    'reconciliation_required' => false,
                ];
            }

            if ($locked->status !== PaymentStatus::PENDING->value) {
                throw InvalidPaymentTransitionException::forTransition($locked->status, 'cancelled');
            }

            $locked->status = PaymentStatus::CANCELLED->value;
            $locked->cancelled_at = now();
            $locked->save();

            $this->orderPaymentSyncService->syncPaymentStatus(
                tenantId: $locked->tenant_id,
                orderId: $locked->order_id,
                status: OrderPaymentStatus::VOIDED,
                reason: $reason
            );

            DB::afterCommit(function () use ($locked, $reason): void {
                PaymentCancelled::dispatch($locked, $reason);
            });

            return [
                'payment_uuid' => $locked->uuid,
                'status' => $locked->status,
                'reconciliation_required' => false,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function markReconciliationRequired(Payment $payment, string $reason): array
    {
        return DB::transaction(function () use ($payment, $reason): array {
            /** @var Payment $locked */
            $locked = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

            $currentMeta = $locked->metadata ?? [];
            $currentMeta['cancellation_reconciliation_required'] = true;
            $currentMeta['cancellation_reason'] = $reason;
            $locked->metadata = $currentMeta;
            $locked->save();

            DB::afterCommit(function () use ($locked, $reason): void {
                PaymentReconciliationRequired::dispatch($locked, $reason);
            });

            return [
                'payment_uuid' => $locked->uuid,
                'status' => $locked->status,
                'reconciliation_required' => true,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function executeAuthorizedVoid(
        Payment $payment,
        string $reason,
        PaymentOperationKey $opKey,
        array $metadata
    ): array {
        // Step 1: Pre-call verification under row lock
        [$lockedPayment, $transaction] = DB::transaction(function () use ($payment, $opKey): array {
            /** @var Payment $locked */
            $locked = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::AUTHORIZED->value) {
                throw InvalidPaymentTransitionException::forTransition($locked->status, 'cancelled');
            }

            /** @var PaymentTransaction|null $existingTx */
            $existingTx = PaymentTransaction::query()
                ->where('payment_operation_key_id', $opKey->id)
                ->first();

            if ($existingTx !== null) {
                return [$locked, $existingTx];
            }

            /** @var PaymentTransaction|null $auth */
            $auth = PaymentTransaction::query()
                ->where('payment_id', $locked->id)
                ->where('operation_type', PaymentOperationType::AUTHORIZE->value)
                ->where('status', PaymentTransactionStatus::SUCCESS->value)
                ->latest('id')
                ->first();

            $providerCode = ($auth instanceof PaymentTransaction && $auth->provider_code !== null ? $auth->provider_code : 'fake');
            $providerIdempotencyKey = "hyp_tx_{$locked->tenant_id}_{$locked->order_id}_{$opKey->id}";

            $transaction = PaymentTransaction::create([
                'tenant_id' => $locked->tenant_id,
                'payment_id' => $locked->id,
                'payment_operation_key_id' => $opKey->id,
                'operation_type' => PaymentOperationType::VOID->value,
                'status' => PaymentTransactionStatus::PENDING->value,
                'amount_minor' => 0,
                'currency' => $locked->currency,
                'provider_code' => $providerCode,
                'provider_reference' => $auth?->provider_reference,
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

        $this->concurrencyBarrier->wait('after_void_pre_call_commit');

        // Step 2: Remote gateway void outside DB transaction
        $providerCode = $transaction->provider_code ?? 'fake';
        $gateway = $this->gatewayRegistry->get($providerCode);

        $request = new GatewayVoidRequest(
            tenantId: $lockedPayment->tenant_id,
            paymentId: $lockedPayment->id,
            transactionId: $transaction->id,
            providerReference: $transaction->provider_reference,
            providerIdempotencyKey: (string) $transaction->provider_idempotency_key,
            metadata: $metadata
        );

        try {
            $result = $gateway->void($request);
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

        // Step 3: Post-call settlement
        return DB::transaction(function () use ($lockedPayment, $transaction, $result, $reason): array {
            /** @var Payment $finalPayment */
            $finalPayment = Payment::query()->where('id', $lockedPayment->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentTransaction $finalTx */
            $finalTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            if ($result->status === PaymentTransactionStatus::SUCCESS) {
                $finalTx->status = PaymentTransactionStatus::SUCCESS->value;
                $finalTx->normalized_error_code = null;
                $finalTx->action_payload = null;
                $finalTx->save();

                $finalPayment->status = PaymentStatus::CANCELLED->value;
                $finalPayment->cancelled_at = now();
                $finalPayment->save();

                $this->orderPaymentSyncService->syncPaymentStatus(
                    tenantId: $finalPayment->tenant_id,
                    orderId: $finalPayment->order_id,
                    status: OrderPaymentStatus::VOIDED,
                    reason: $reason
                );

                DB::afterCommit(function () use ($finalPayment, $reason): void {
                    PaymentCancelled::dispatch($finalPayment, $reason);
                });
            } else {
                $finalTx->status = PaymentTransactionStatus::FAILURE->value;
                $finalTx->normalized_error_code = $result->normalizedErrorCode ?? 'void_declined';
                $finalTx->action_payload = null;
                $finalTx->save();
            }

            return $this->reconciliationService->formatResponse($finalPayment, $finalTx);
        });
    }
}
