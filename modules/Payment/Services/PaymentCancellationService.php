<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Contracts\PaymentIdempotencyServiceInterface;
use Modules\Payment\DTOs\GatewayVoidRequest;
use Modules\Payment\Enums\PaymentOperationType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Events\PaymentCancelled;
use Modules\Payment\Events\PaymentReconciliationRequired;
use Modules\Payment\Exceptions\InvalidPaymentTransitionException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;

class PaymentCancellationService
{
    public function __construct(
        private readonly PaymentIdempotencyServiceInterface $idempotencyService,
        private readonly PaymentGatewayRegistryInterface $gatewayRegistry,
        private readonly OrderPaymentSynchronizationServiceInterface $orderPaymentSyncService
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function cancel(
        int $tenantId,
        string $paymentUuid,
        string $reason = 'Payment cancelled',
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
            'reason' => $reason,
            'metadata' => $metadata,
        ];

        return $this->idempotencyService->execute(
            tenantId: $tenantId,
            orderId: $payment->order_id,
            paymentId: $payment->id,
            operationType: 'cancel_payment',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function (PaymentOperationKey $opKey) use ($payment, $reason, $metadata): array {
                return $this->executeCancellation($payment, $reason, $opKey, $metadata);
            }
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function executeCancellation(
        Payment $payment,
        string $reason,
        PaymentOperationKey $opKey,
        array $metadata
    ): array {
        if ($payment->status === PaymentStatus::PENDING->value) {
            return DB::transaction(function () use ($payment, $reason): array {
                /** @var Payment $lockedPayment */
                $lockedPayment = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

                $lockedPayment->status = PaymentStatus::CANCELLED->value;
                $lockedPayment->cancelled_at = now();
                $lockedPayment->save();

                $this->orderPaymentSyncService->syncPaymentStatus(
                    tenantId: $lockedPayment->tenant_id,
                    orderId: $lockedPayment->order_id,
                    status: OrderPaymentStatus::VOIDED,
                    reason: $reason
                );

                DB::afterCommit(function () use ($lockedPayment, $reason): void {
                    PaymentCancelled::dispatch($lockedPayment, $reason);
                });

                return [
                    'payment_id' => $lockedPayment->id,
                    'payment_uuid' => $lockedPayment->uuid,
                    'status' => $lockedPayment->status,
                ];
            });
        }

        if ($payment->status === PaymentStatus::AUTHORIZED->value) {
            // Step 1: Pre-call void record
            [$lockedPayment, $transaction, $authTx] = DB::transaction(function () use ($payment, $opKey): array {
                /** @var Payment $locked */
                $locked = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

                /** @var PaymentTransaction|null $existingTx */
                $existingTx = PaymentTransaction::query()
                    ->where('payment_operation_key_id', $opKey->id)
                    ->first();

                if ($existingTx !== null) {
                    return [$locked, $existingTx, null];
                }

                /** @var PaymentTransaction|null $auth */
                $auth = PaymentTransaction::query()
                    ->where('payment_id', $locked->id)
                    ->where('operation_type', PaymentOperationType::AUTHORIZE->value)
                    ->where('status', PaymentTransactionStatus::SUCCESS->value)
                    ->latest('id')
                    ->first();

                $providerCode = $auth->provider_code ?? 'fake';
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

                return [$locked, $transaction, $auth];
            });

            // Step 2: Remote gateway void
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

            // Step 3: Post-call settlement
            return DB::transaction(function () use ($lockedPayment, $transaction, $result, $reason): array {
                /** @var Payment $finalPayment */
                $finalPayment = Payment::query()->where('id', $lockedPayment->id)->lockForUpdate()->firstOrFail();
                /** @var PaymentTransaction $finalTx */
                $finalTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

                if ($result->status === PaymentTransactionStatus::SUCCESS) {
                    $finalTx->status = PaymentTransactionStatus::SUCCESS->value;
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
                    $finalTx->save();
                }

                return [
                    'payment_id' => $finalPayment->id,
                    'payment_uuid' => $finalPayment->uuid,
                    'status' => $finalPayment->status,
                    'transaction_status' => $finalTx->status,
                ];
            });
        }

        if (in_array($payment->status, [PaymentStatus::CAPTURED->value, PaymentStatus::PARTIALLY_REFUNDED->value], true)) {
            // Captured payment: do not auto-refund synchronously
            DB::transaction(function () use ($payment): void {
                /** @var Payment $locked */
                $locked = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();
                $meta = $locked->metadata ?? [];
                $meta['cancellation_reconciliation_required'] = true;
                $locked->metadata = $meta;
                $locked->save();

                DB::afterCommit(function () use ($locked): void {
                    PaymentReconciliationRequired::dispatch($locked, 'Order was cancelled after funds were captured. Manual refund reconciliation required.');
                });
            });

            return [
                'payment_id' => $payment->id,
                'payment_uuid' => $payment->uuid,
                'status' => $payment->status,
                'reconciliation_required' => true,
            ];
        }

        throw InvalidPaymentTransitionException::forTransition($payment->status, 'cancelled');
    }
}
