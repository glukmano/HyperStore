<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Order\Models\Order;
use Modules\Payment\Contracts\PaymentConcurrencyBarrierInterface;
use Modules\Payment\Contracts\PaymentGatewayInterface;
use Modules\Payment\Contracts\PaymentGatewayReconciliationInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Contracts\PaymentIdempotencyServiceInterface;
use Modules\Payment\DTOs\GatewayPaymentRequest;
use Modules\Payment\DTOs\GatewayReconciliationRequest;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\DTOs\PaymentActionDTO;
use Modules\Payment\Enums\PaymentOperationType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Enums\ReconciliationStatus;
use Modules\Payment\Events\PaymentActionRequired;
use Modules\Payment\Events\PaymentAuthorized;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Events\PaymentCreated;
use Modules\Payment\Exceptions\OrderAlreadyCancelledException;
use Modules\Payment\Exceptions\PaymentAmountMismatchException;
use Modules\Payment\Exceptions\PaymentCurrencyMismatchException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;

class PaymentInitiationService
{
    public function __construct(
        private readonly PaymentIdempotencyServiceInterface $idempotencyService,
        private readonly PaymentGatewayRegistryInterface $gatewayRegistry,
        private readonly OrderPaymentSynchronizationServiceInterface $orderPaymentSyncService,
        private readonly PaymentConcurrencyBarrierInterface $concurrencyBarrier
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function initiatePayment(InitiatePaymentDTO $dto): array
    {
        /** @var Order $order */
        $order = Order::query()
            ->where('tenant_id', $dto->tenantId)
            ->where('id', $dto->orderId)
            ->firstOrFail();

        if ($order->order_status === 'cancelled') {
            throw OrderAlreadyCancelledException::forOrder($order->id);
        }

        if ($dto->amountMinor !== $order->grand_total_minor) {
            throw PaymentAmountMismatchException::forMismatch($dto->amountMinor, $order->grand_total_minor);
        }

        if (strtoupper($dto->currency) !== strtoupper($order->currency)) {
            throw PaymentCurrencyMismatchException::forMismatch($dto->currency, $order->currency);
        }

        $payload = [
            'tenant_id' => $dto->tenantId,
            'order_id' => $dto->orderId,
            'amount_minor' => $dto->amountMinor,
            'currency' => strtoupper($dto->currency),
            'provider_code' => $dto->providerCode,
            'payment_method_type' => $dto->paymentMethodType,
            'payment_method_reference' => $dto->paymentMethodReference,
            'capture_immediately' => $dto->captureImmediately,
            'metadata' => $dto->metadata,
        ];

        return $this->idempotencyService->execute(
            tenantId: $dto->tenantId,
            orderId: $dto->orderId,
            paymentId: null,
            operationType: 'initiate_payment',
            idempotencyKey: $dto->idempotencyKey,
            requestPayload: $payload,
            callback: function (PaymentOperationKey $opKey) use ($dto, $order): array {
                if ($order->grand_total_minor === 0) {
                    return $this->executeZeroTotalSettlement($dto, $order, $opKey);
                }

                return $this->executeGatewayPayment($dto, $order, $opKey);
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function executeZeroTotalSettlement(InitiatePaymentDTO $dto, Order $order, PaymentOperationKey $opKey): array
    {
        return DB::transaction(function () use ($dto, $order, $opKey): array {
            /** @var Payment $payment */
            $payment = Payment::query()->firstOrCreate(
                [
                    'tenant_id' => $dto->tenantId,
                    'order_id' => $order->id,
                ],
                [
                    'amount_minor' => 0,
                    'currency' => $order->currency,
                    'status' => PaymentStatus::CAPTURED->value,
                    'captured_amount_minor' => 0,
                    'captured_at' => now(),
                    'metadata' => array_merge($dto->metadata, ['settlement_type' => 'zero_total']),
                ]
            );

            $opKey->payment_id = $payment->id;
            $opKey->save();

            /** @var PaymentTransaction $transaction */
            $transaction = PaymentTransaction::query()->firstOrCreate(
                [
                    'payment_operation_key_id' => $opKey->id,
                ],
                [
                    'tenant_id' => $dto->tenantId,
                    'payment_id' => $payment->id,
                    'operation_type' => PaymentOperationType::ZERO_TOTAL_SETTLEMENT->value,
                    'status' => PaymentTransactionStatus::SUCCESS->value,
                    'amount_minor' => 0,
                    'currency' => $order->currency,
                    'provider_code' => null,
                    'provider_reference' => null,
                ]
            );

            $this->orderPaymentSyncService->syncPaymentStatus(
                tenantId: $dto->tenantId,
                orderId: $order->id,
                status: OrderPaymentStatus::PAID,
                reason: 'Zero total internal settlement'
            );

            DB::afterCommit(function () use ($payment, $transaction): void {
                PaymentCreated::dispatch($payment);
                PaymentCaptured::dispatch($payment, $transaction);
            });

            return [
                'payment_id' => $payment->id,
                'payment_uuid' => $payment->uuid,
                'status' => $payment->status,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'captured_amount_minor' => $payment->captured_amount_minor,
                'transaction_id' => $transaction->id,
                'transaction_status' => $transaction->status,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function executeGatewayPayment(InitiatePaymentDTO $dto, Order $order, PaymentOperationKey $opKey): array
    {
        $providerCode = $dto->providerCode ?? 'fake';
        $gateway = $this->gatewayRegistry->get($providerCode);

        // 1. Pre-Call Phase under DB transaction
        [$payment, $transaction] = DB::transaction(function () use ($dto, $order, $opKey, $providerCode): array {
            /** @var Payment $payment */
            $payment = Payment::query()->firstOrCreate(
                [
                    'tenant_id' => $dto->tenantId,
                    'order_id' => $order->id,
                ],
                [
                    'amount_minor' => $dto->amountMinor,
                    'currency' => strtoupper($dto->currency),
                    'status' => PaymentStatus::PENDING->value,
                    'metadata' => $dto->metadata,
                ]
            );

            $opKey->payment_id = $payment->id;
            $opKey->save();

            /** @var PaymentTransaction|null $existingTx */
            $existingTx = PaymentTransaction::query()
                ->where('payment_operation_key_id', $opKey->id)
                ->first();

            if ($existingTx !== null) {
                return [$payment, $existingTx];
            }

            $operationType = $dto->captureImmediately
                ? PaymentOperationType::PURCHASE->value
                : PaymentOperationType::AUTHORIZE->value;

            $providerIdempotencyKey = "hyp_tx_{$dto->tenantId}_{$order->id}_{$opKey->id}";

            $transaction = PaymentTransaction::create([
                'tenant_id' => $dto->tenantId,
                'payment_id' => $payment->id,
                'payment_operation_key_id' => $opKey->id,
                'operation_type' => $operationType,
                'status' => PaymentTransactionStatus::PENDING->value,
                'amount_minor' => $dto->amountMinor,
                'currency' => strtoupper($dto->currency),
                'provider_code' => $providerCode,
                'payment_method_type' => $dto->paymentMethodType,
                'provider_idempotency_key' => $providerIdempotencyKey,
            ]);

            return [$payment, $transaction];
        });

        // Check if existing transaction is already completed or unknown
        if ($transaction->status === PaymentTransactionStatus::SUCCESS->value) {
            $refreshed = $payment->fresh();
            if ($refreshed === null) {
                throw new \RuntimeException('Payment not found');
            }

            return $this->formatResponse($refreshed, $transaction);
        }

        // If transaction is unknown, attempt reconciliation
        if ($transaction->status === PaymentTransactionStatus::UNKNOWN->value) {
            return $this->reconcileUnknownTransaction($gateway, $payment, $transaction, $dto, $order);
        }

        $this->concurrencyBarrier->wait('after_pre_call_commit');

        // 2. Remote Gateway Call outside DB transaction
        $request = new GatewayPaymentRequest(
            tenantId: $dto->tenantId,
            paymentId: $payment->id,
            transactionId: $transaction->id,
            amountMinor: $dto->amountMinor,
            currency: $dto->currency,
            paymentMethodType: $dto->paymentMethodType,
            paymentMethodReference: $dto->paymentMethodReference,
            providerIdempotencyKey: (string) $transaction->provider_idempotency_key,
            metadata: $dto->metadata
        );

        try {
            $result = $dto->captureImmediately
                ? $gateway->purchase($request)
                : $gateway->authorize($request);
        } catch (Exception $e) {
            // Indeterminate network failure -> mark transaction unknown
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

        // 3. Post-Call Phase under DB transaction
        return DB::transaction(function () use ($payment, $transaction, $result, $dto, $order): array {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            if ($result->status === PaymentTransactionStatus::SUCCESS) {
                $lockedTx->status = PaymentTransactionStatus::SUCCESS->value;
                $lockedTx->provider_reference = $result->providerReference;
                $lockedTx->provider_response_code = $result->providerResponseCode;
                $lockedTx->save();

                if ($dto->captureImmediately) {
                    $lockedPayment->status = PaymentStatus::CAPTURED->value;
                    $lockedPayment->captured_amount_minor = $dto->amountMinor;
                    $lockedPayment->captured_at = now();
                    $lockedPayment->save();

                    $this->orderPaymentSyncService->syncPaymentStatus(
                        tenantId: $dto->tenantId,
                        orderId: $order->id,
                        status: OrderPaymentStatus::PAID,
                        reason: 'Payment captured successfully via gateway'
                    );

                    DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                        PaymentCaptured::dispatch($lockedPayment, $lockedTx);
                    });
                } else {
                    $lockedPayment->status = PaymentStatus::AUTHORIZED->value;
                    $lockedPayment->authorized_amount_minor = $dto->amountMinor;
                    $lockedPayment->authorized_at = now();
                    $lockedPayment->save();

                    $this->orderPaymentSyncService->syncPaymentStatus(
                        tenantId: $dto->tenantId,
                        orderId: $order->id,
                        status: OrderPaymentStatus::AUTHORIZED,
                        reason: 'Payment authorized successfully via gateway'
                    );

                    DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                        PaymentAuthorized::dispatch($lockedPayment, $lockedTx);
                    });
                }
            } elseif ($result->status === PaymentTransactionStatus::ACTION_REQUIRED && $result->action !== null) {
                $lockedTx->status = PaymentTransactionStatus::ACTION_REQUIRED->value;
                $lockedTx->provider_reference = $result->providerReference;
                $lockedTx->action_type = $result->action->type->value;
                $lockedTx->action_payload = $result->action->payload;
                $lockedTx->save();

                DB::afterCommit(function () use ($lockedPayment, $lockedTx, $result): void {
                    /** @var PaymentActionDTO $action */
                    $action = $result->action;
                    PaymentActionRequired::dispatch($lockedPayment, $lockedTx, $action);
                });
            } else {
                $lockedTx->status = PaymentTransactionStatus::FAILURE->value;
                $lockedTx->provider_reference = $result->providerReference;
                $lockedTx->provider_response_code = $result->providerResponseCode;
                $lockedTx->normalized_error_code = $result->normalizedErrorCode ?? 'declined';
                $lockedTx->save();
                // Notice: lockedPayment remains in PENDING for retry!
            }

            return $this->formatResponse($lockedPayment, $lockedTx);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function reconcileUnknownTransaction(
        PaymentGatewayInterface $gateway,
        Payment $payment,
        PaymentTransaction $transaction,
        InitiatePaymentDTO $dto,
        Order $order
    ): array {
        if (! $gateway instanceof PaymentGatewayReconciliationInterface || ! $gateway->supportsReconciliation()) {
            throw PaymentReconciliationPendingException::forTransaction($transaction->id);
        }

        $reconcileReq = new GatewayReconciliationRequest(
            tenantId: $dto->tenantId,
            providerReference: $transaction->provider_reference,
            providerIdempotencyKey: $transaction->provider_idempotency_key,
            operationType: $transaction->operation_type,
            expectedAmountMinor: $dto->amountMinor,
            expectedCurrency: $dto->currency
        );

        $reconcileRes = $gateway->reconcileOperation($reconcileReq);

        if ($reconcileRes->status === ReconciliationStatus::SUCCESS) {
            return DB::transaction(function () use ($payment, $transaction, $reconcileRes, $dto, $order): array {
                /** @var Payment $lockedPayment */
                $lockedPayment = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

                $lockedTx->status = PaymentTransactionStatus::SUCCESS->value;
                $lockedTx->provider_reference = $reconcileRes->providerReference;
                $lockedTx->save();

                if ($dto->captureImmediately) {
                    $lockedPayment->status = PaymentStatus::CAPTURED->value;
                    $lockedPayment->captured_amount_minor = $dto->amountMinor;
                    $lockedPayment->captured_at = now();
                    $lockedPayment->save();

                    $this->orderPaymentSyncService->syncPaymentStatus(
                        tenantId: $dto->tenantId,
                        orderId: $order->id,
                        status: OrderPaymentStatus::PAID,
                        reason: 'Payment reconciled and captured successfully'
                    );

                    DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                        PaymentCaptured::dispatch($lockedPayment, $lockedTx);
                    });
                } else {
                    $lockedPayment->status = PaymentStatus::AUTHORIZED->value;
                    $lockedPayment->authorized_amount_minor = $dto->amountMinor;
                    $lockedPayment->authorized_at = now();
                    $lockedPayment->save();

                    $this->orderPaymentSyncService->syncPaymentStatus(
                        tenantId: $dto->tenantId,
                        orderId: $order->id,
                        status: OrderPaymentStatus::AUTHORIZED,
                        reason: 'Payment reconciled and authorized successfully'
                    );

                    DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                        PaymentAuthorized::dispatch($lockedPayment, $lockedTx);
                    });
                }

                return $this->formatResponse($lockedPayment, $lockedTx);
            });
        }

        if ($reconcileRes->status === ReconciliationStatus::FAILURE) {
            DB::transaction(function () use ($transaction, $reconcileRes): void {
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();
                $lockedTx->status = PaymentTransactionStatus::FAILURE->value;
                $lockedTx->normalized_error_code = $reconcileRes->normalizedErrorCode ?? 'declined';
                $lockedTx->save();
            });
        }

        // still_pending or unknown -> leave transaction in UNKNOWN status, throw pending exception
        throw PaymentReconciliationPendingException::forTransaction($transaction->id);
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
            'action_type' => $transaction->action_type,
            'action_payload' => $transaction->action_payload,
            'provider_reference' => $transaction->provider_reference,
            'normalized_error_code' => $transaction->normalized_error_code,
        ];
    }
}
