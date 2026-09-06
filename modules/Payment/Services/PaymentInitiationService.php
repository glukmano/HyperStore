<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Order\Models\Order;
use Modules\Payment\Contracts\PaymentConcurrencyBarrierInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Contracts\PaymentIdempotencyServiceInterface;
use Modules\Payment\Contracts\RecurringPaymentGatewayInterface;
use Modules\Payment\DTOs\GatewayOffSessionChargeRequest;
use Modules\Payment\DTOs\GatewayPaymentRequest;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\DTOs\PaymentActionDTO;
use Modules\Payment\Enums\PaymentOperationType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Events\PaymentActionRequired;
use Modules\Payment\Events\PaymentAuthorized;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Events\PaymentCreated;
use Modules\Payment\Exceptions\GatewayDoesNotSupportRecurringException;
use Modules\Payment\Exceptions\GatewayIndeterminateOutcomeException;
use Modules\Payment\Exceptions\GatewayUnavailableException;
use Modules\Payment\Exceptions\InvalidPaymentTransitionException;
use Modules\Payment\Exceptions\OrderAlreadyCancelledException;
use Modules\Payment\Exceptions\PaymentAmountMismatchException;
use Modules\Payment\Exceptions\PaymentCurrencyMismatchException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;
use Throwable;

class PaymentInitiationService
{
    public function __construct(
        private readonly PaymentIdempotencyServiceInterface $idempotencyService,
        private readonly PaymentGatewayRegistryInterface $gatewayRegistry,
        private readonly OrderPaymentSynchronizationServiceInterface $orderPaymentSyncService,
        private readonly PaymentConcurrencyBarrierInterface $concurrencyBarrier,
        private readonly PaymentTransactionReconciliationService $reconciliationService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function initiatePayment(InitiatePaymentDTO $dto): array
    {
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
            callback: fn (PaymentOperationKey $opKey): array => $this->executeInitiation($dto, $opKey)
        );
    }

    /**
     * Owner Delta §9/§11: charges a previously-tokenized CustomerPaymentMethod
     * off-session, for an unattended Subscription renewal. The
     * providerIdempotencyKey is computed by the CALLER at billing-period
     * claim time (D.39) — BEFORE this method is ever invoked — never
     * generated here, so a retried HTTP call is idempotent at the
     * provider's own layer too.
     *
     * @return array<string, mixed>
     */
    public function initiateOffSessionPayment(
        int $tenantId,
        int $orderId,
        int $amountMinor,
        string $currency,
        string $gatewayProviderCode,
        string $gatewayReference,
        string $providerIdempotencyKey
    ): array {
        /** @var Order $order */
        $order = Order::query()->where('tenant_id', $tenantId)->where('id', $orderId)->firstOrFail();

        $amountDueMinor = $order->amount_due_minor ?? $order->grand_total_minor;
        if ($amountMinor !== $amountDueMinor) {
            throw PaymentAmountMismatchException::forAmounts($amountMinor, $amountDueMinor);
        }
        if (strtoupper($currency) !== strtoupper($order->currency)) {
            throw PaymentCurrencyMismatchException::forCurrencies($currency, $order->currency);
        }

        $gateway = $this->gatewayRegistry->get($gatewayProviderCode);
        if (! $gateway instanceof RecurringPaymentGatewayInterface) {
            throw GatewayDoesNotSupportRecurringException::forProvider($gatewayProviderCode);
        }

        [$payment, $transaction] = DB::transaction(function () use ($tenantId, $order, $amountMinor, $currency, $gatewayProviderCode, $providerIdempotencyKey): array {
            /** @var Payment $payment */
            $payment = Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                $payment = Payment::create([
                    'tenant_id' => $tenantId,
                    'order_id' => $order->id,
                    'status' => PaymentStatus::PENDING->value,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'authorized_amount_minor' => 0,
                    'captured_amount_minor' => 0,
                    'refunded_amount_minor' => 0,
                    'metadata' => [],
                ]);

                DB::afterCommit(function () use ($payment): void {
                    PaymentCreated::dispatch($payment);
                });
            }

            /** @var PaymentTransaction|null $existingTx */
            $existingTx = PaymentTransaction::query()
                ->where('payment_id', $payment->id)
                ->where('provider_idempotency_key', $providerIdempotencyKey)
                ->first();

            if ($existingTx !== null) {
                return [$payment, $existingTx];
            }

            /** @var PaymentTransaction $transaction */
            $transaction = PaymentTransaction::create([
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
                'operation_type' => PaymentOperationType::PURCHASE->value,
                'status' => PaymentTransactionStatus::PENDING->value,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'provider_code' => $gatewayProviderCode,
                'provider_idempotency_key' => $providerIdempotencyKey,
            ]);

            return [$payment, $transaction];
        });

        // Monotonicity guards — mirrors PaymentTransactionReconciliationService's
        // own discipline. A pre-existing transaction for this EXACT
        // providerIdempotencyKey means this is a replay of an attempt
        // already made (not a new attempt — a new attempt always carries
        // its own fresh key, derived by the caller), so the outcome must
        // never be re-decided by calling the gateway again:
        //  - SUCCESS: replay the success.
        //  - UNKNOWN (a prior timeout): reconcile with the provider to
        //    learn the real outcome — NEVER issue another charge while
        //    the original request's outcome is still indeterminate
        //    (Owner Delta §11).
        //  - FAILURE: replay the definitive failure — do not reinterpret
        //    or retry it under the same key. A genuinely new dunning
        //    retry attempt must supply a NEW providerIdempotencyKey (see
        //    SubscriptionRenewalService::maybeRetryPastDue()).
        if ($transaction->status === PaymentTransactionStatus::SUCCESS->value) {
            return $this->reconciliationService->formatResponse($payment->fresh() ?? $payment, $transaction);
        }
        if ($transaction->status === PaymentTransactionStatus::UNKNOWN->value) {
            return $this->reconciliationService->reconcile($transaction, $payment);
        }
        if ($transaction->status === PaymentTransactionStatus::FAILURE->value) {
            return $this->reconciliationService->formatResponse($payment->fresh() ?? $payment, $transaction);
        }

        $request = new GatewayOffSessionChargeRequest(
            tenantId: $tenantId,
            paymentId: (int) $payment->id,
            transactionId: (int) $transaction->id,
            amountMinor: $amountMinor,
            currency: $currency,
            providerReference: $gatewayReference,
            providerIdempotencyKey: $providerIdempotencyKey
        );

        try {
            $result = $gateway->chargeOffSession($request);
        } catch (GatewayIndeterminateOutcomeException) {
            DB::transaction(function () use ($transaction): void {
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();
                $lockedTx->status = PaymentTransactionStatus::UNKNOWN->value;
                $lockedTx->normalized_error_code = 'gateway_timeout';
                $lockedTx->save();
            });

            throw PaymentReconciliationPendingException::forTransaction($transaction->id);
        } catch (Throwable) {
            return DB::transaction(function () use ($payment, $transaction): array {
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();
                $lockedTx->status = PaymentTransactionStatus::FAILURE->value;
                $lockedTx->normalized_error_code = 'gateway_error';
                $lockedTx->save();

                return $this->reconciliationService->formatResponse($payment->fresh() ?? $payment, $lockedTx);
            });
        }

        return DB::transaction(function () use ($payment, $transaction, $result, $amountMinor, $order): array {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            if ($result->status === PaymentTransactionStatus::SUCCESS) {
                $lockedTx->status = PaymentTransactionStatus::SUCCESS->value;
                $lockedTx->provider_reference = $result->providerReference;
                $lockedTx->normalized_error_code = null;
                $lockedTx->save();

                $lockedPayment->status = PaymentStatus::CAPTURED->value;
                $lockedPayment->captured_amount_minor = $amountMinor;
                $lockedPayment->captured_at = now();
                $lockedPayment->save();

                $this->orderPaymentSyncService->syncPaymentStatus(
                    tenantId: $order->tenant_id,
                    orderId: $order->id,
                    status: OrderPaymentStatus::PAID,
                    reason: 'Off-session recurring payment captured successfully'
                );

                DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                    PaymentCaptured::dispatch($lockedPayment, $lockedTx);
                });
            } else {
                $lockedTx->status = PaymentTransactionStatus::FAILURE->value;
                $lockedTx->provider_reference = $result->providerReference;
                $lockedTx->normalized_error_code = $result->normalizedErrorCode ?? 'declined';
                $lockedTx->save();
            }

            return $this->reconciliationService->formatResponse($lockedPayment, $lockedTx);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function executeInitiation(InitiatePaymentDTO $dto, PaymentOperationKey $opKey): array
    {
        /** @var Order $order */
        $order = Order::query()->where('tenant_id', $dto->tenantId)->where('id', $dto->orderId)->firstOrFail();

        if ($order->order_status === 'cancelled') {
            throw OrderAlreadyCancelledException::forOrder($order->id);
        }

        // C.25/C.27: relaxed from grand_total_minor to amount_due_minor —
        // equal to grand_total_minor when no Store Value was applied (zero
        // behavior change for every existing, non-Store-Value Order).
        $amountDueMinor = $order->amount_due_minor ?? $order->grand_total_minor;
        if ($dto->amountMinor !== $amountDueMinor) {
            throw PaymentAmountMismatchException::forAmounts($dto->amountMinor, $amountDueMinor);
        }

        if (strtoupper($dto->currency) !== strtoupper($order->currency)) {
            throw PaymentCurrencyMismatchException::forCurrencies($dto->currency, $order->currency);
        }

        // Branch 1: Zero-Total Order Settlement
        if ($dto->amountMinor === 0) {
            return $this->handleZeroTotalOrder($dto, $order, $opKey);
        }

        // Branch 2: Standard Monetary Payment via Provider
        return $this->handleGatewayPayment($dto, $order, $opKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleZeroTotalOrder(InitiatePaymentDTO $dto, Order $order, PaymentOperationKey $opKey): array
    {
        return DB::transaction(function () use ($dto, $order, $opKey): array {
            /** @var Payment $payment */
            $payment = Payment::query()
                ->firstOrCreate(
                    ['tenant_id' => $dto->tenantId, 'order_id' => $order->id],
                    [
                        'status' => PaymentStatus::PENDING->value,
                        'amount_minor' => 0,
                        'currency' => $dto->currency,
                        'authorized_amount_minor' => 0,
                        'captured_amount_minor' => 0,
                        'refunded_amount_minor' => 0,
                        'metadata' => $dto->metadata,
                    ]
                );

            /** @var PaymentTransaction $transaction */
            $transaction = PaymentTransaction::query()
                ->firstOrCreate(
                    ['payment_operation_key_id' => $opKey->id],
                    [
                        'tenant_id' => $dto->tenantId,
                        'payment_id' => $payment->id,
                        'operation_type' => PaymentOperationType::ZERO_TOTAL_SETTLEMENT->value,
                        'status' => PaymentTransactionStatus::SUCCESS->value,
                        'amount_minor' => 0,
                        'currency' => $dto->currency,
                    ]
                );

            $payment->status = PaymentStatus::CAPTURED->value;
            $payment->captured_at = now();
            $payment->save();

            $this->orderPaymentSyncService->syncPaymentStatus(
                tenantId: $dto->tenantId,
                orderId: $order->id,
                status: OrderPaymentStatus::PAID,
                reason: 'Zero-total order settled internally'
            );

            DB::afterCommit(function () use ($payment, $transaction): void {
                PaymentCreated::dispatch($payment);
                PaymentCaptured::dispatch($payment, $transaction);
            });

            return $this->reconciliationService->formatResponse($payment, $transaction);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function handleGatewayPayment(InitiatePaymentDTO $dto, Order $order, PaymentOperationKey $opKey): array
    {
        $providerCode = $dto->providerCode;
        if ($providerCode === null) {
            if (! $this->gatewayRegistry->hasDefault()) {
                throw GatewayUnavailableException::forProvider('default');
            }
            $providerCode = $this->gatewayRegistry->default()->getProviderCode();
        }
        $gateway = $this->gatewayRegistry->get($providerCode);

        // 1. Pre-Call Phase under DB transaction
        [$payment, $transaction] = DB::transaction(function () use ($dto, $order, $opKey, $providerCode): array {
            /** @var Payment $payment */
            $payment = Payment::query()
                ->where('tenant_id', $dto->tenantId)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                $payment = Payment::create([
                    'tenant_id' => $dto->tenantId,
                    'order_id' => $order->id,
                    'status' => PaymentStatus::PENDING->value,
                    'amount_minor' => $dto->amountMinor,
                    'currency' => $dto->currency,
                    'authorized_amount_minor' => 0,
                    'captured_amount_minor' => 0,
                    'refunded_amount_minor' => 0,
                    'metadata' => $dto->metadata,
                ]);

                DB::afterCommit(function () use ($payment): void {
                    PaymentCreated::dispatch($payment);
                });
            } else {
                if ($payment->status !== PaymentStatus::PENDING->value) {
                    throw InvalidPaymentTransitionException::forTransition($payment->status, 'initiated');
                }
            }

            /** @var PaymentTransaction|null $existingTx */
            $existingTx = PaymentTransaction::query()
                ->where('payment_operation_key_id', $opKey->id)
                ->first();

            if ($existingTx !== null) {
                return [$payment, $existingTx];
            }

            $opType = $dto->captureImmediately ? PaymentOperationType::PURCHASE : PaymentOperationType::AUTHORIZE;
            $providerIdempotencyKey = "hyp_tx_{$dto->tenantId}_{$order->id}_{$opKey->id}";

            /** @var PaymentTransaction $transaction */
            $transaction = PaymentTransaction::create([
                'tenant_id' => $dto->tenantId,
                'payment_id' => $payment->id,
                'payment_operation_key_id' => $opKey->id,
                'operation_type' => $opType->value,
                'status' => PaymentTransactionStatus::PENDING->value,
                'amount_minor' => $dto->amountMinor,
                'currency' => $dto->currency,
                'provider_code' => $providerCode,
                'payment_method_type' => $dto->paymentMethodType,
                'provider_idempotency_key' => $providerIdempotencyKey,
            ]);

            return [$payment, $transaction];
        });

        // If existing transaction is already completed, return replay
        if ($transaction->status === PaymentTransactionStatus::SUCCESS->value) {
            return $this->reconciliationService->formatResponse($payment->fresh() ?? $payment, $transaction);
        }

        // If transaction is unknown, route to shared reconciliation
        if ($transaction->status === PaymentTransactionStatus::UNKNOWN->value) {
            return $this->reconciliationService->reconcile($transaction, $payment, $opKey);
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
            return DB::transaction(function () use ($payment, $transaction): array {
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();
                $lockedTx->status = PaymentTransactionStatus::FAILURE->value;
                $lockedTx->normalized_error_code = 'gateway_error';
                $lockedTx->action_payload = null;
                $lockedTx->save();

                return $this->reconciliationService->formatResponse($payment->fresh() ?? $payment, $lockedTx);
            });
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
                $lockedTx->normalized_error_code = null;
                $lockedTx->action_payload = null;
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
                $lockedTx->action_payload = null;
                $lockedTx->save();
            }

            return $this->reconciliationService->formatResponse($lockedPayment, $lockedTx);
        });
    }
}
