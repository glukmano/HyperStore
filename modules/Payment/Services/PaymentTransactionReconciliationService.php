<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\Contracts\PaymentGatewayReconciliationInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\GatewayReconciliationRequest;
use Modules\Payment\Enums\PaymentOperationType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Enums\ReconciliationStatus;
use Modules\Payment\Events\PaymentAuthorized;
use Modules\Payment\Events\PaymentCancelled;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;
use Modules\Payment\Exceptions\GatewayUnavailableException;
use Modules\Payment\Exceptions\InvalidPaymentTransitionException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;
use RuntimeException;

class PaymentTransactionReconciliationService
{
    public function __construct(
        private readonly PaymentGatewayRegistryInterface $gatewayRegistry,
        private readonly OrderPaymentSynchronizationServiceInterface $orderPaymentSyncService
    ) {}

    /**
     * Reconcile an existing PaymentTransaction with the payment provider.
     *
     * @return array<string, mixed>
     */
    public function reconcile(
        PaymentTransaction $transaction,
        Payment $payment,
        ?PaymentOperationKey $opKey = null
    ): array {
        // Monotonicity guard 1: If transaction already reached SUCCESS, return replay/current result immediately.
        if ($transaction->status === PaymentTransactionStatus::SUCCESS->value) {
            return $this->formatResponse($payment->fresh() ?? $payment, $transaction);
        }

        // Monotonicity guard 2: If transaction is already terminal FAILURE, do not reinterpret without accepted recovery.
        if ($transaction->status === PaymentTransactionStatus::FAILURE->value) {
            return $this->formatResponse($payment->fresh() ?? $payment, $transaction);
        }

        // Only UNKNOWN (or ACTION_REQUIRED awaiting verification) may enter provider reconciliation.
        $providerCode = $transaction->provider_code;
        if ($providerCode === null) {
            throw GatewayUnavailableException::forProvider('unknown');
        }
        $gateway = $this->gatewayRegistry->get($providerCode);

        if (! $gateway instanceof PaymentGatewayReconciliationInterface || ! $gateway->supportsReconciliation()) {
            throw new GatewayUnavailableException("Payment gateway [{$providerCode}] does not support reconciliation.");
        }

        $reconciliationResult = $gateway->reconcileOperation(new GatewayReconciliationRequest(
            tenantId: $transaction->tenant_id,
            providerReference: $transaction->provider_reference,
            providerIdempotencyKey: $transaction->provider_idempotency_key,
            operationType: $transaction->operation_type,
            expectedAmountMinor: $transaction->amount_minor,
            expectedCurrency: $transaction->currency
        ));

        if ($reconciliationResult->status === ReconciliationStatus::STILL_PENDING || $reconciliationResult->status === ReconciliationStatus::UNKNOWN) {
            throw new PaymentReconciliationPendingException("Payment transaction [{$transaction->uuid}] is still indeterminate with provider [{$providerCode}].");
        }

        if ($reconciliationResult->status === ReconciliationStatus::FAILURE) {
            return DB::transaction(function () use ($payment, $transaction, $reconciliationResult): array {
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

                // Monotonicity check: Never overwrite SUCCESS with FAILURE
                if ($lockedTx->status === PaymentTransactionStatus::SUCCESS->value) {
                    return $this->formatResponse($payment->fresh() ?? $payment, $lockedTx);
                }

                $lockedTx->status = PaymentTransactionStatus::FAILURE->value;
                $lockedTx->normalized_error_code = $reconciliationResult->normalizedErrorCode ?? 'reconciliation_failed';
                $lockedTx->action_payload = null;
                $lockedTx->save();

                return $this->formatResponse($payment->fresh() ?? $payment, $lockedTx);
            });
        }

        if ($reconciliationResult->status === ReconciliationStatus::ACTION_REQUIRED) {
            return DB::transaction(function () use ($payment, $transaction, $reconciliationResult): array {
                /** @var PaymentTransaction $lockedTx */
                $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

                if ($lockedTx->status === PaymentTransactionStatus::SUCCESS->value) {
                    return $this->formatResponse($payment->fresh() ?? $payment, $lockedTx);
                }

                $lockedTx->status = PaymentTransactionStatus::ACTION_REQUIRED->value;
                $lockedTx->action_type = $reconciliationResult->action?->type->value;
                $lockedTx->action_payload = $reconciliationResult->action?->payload;
                $lockedTx->save();

                return $this->formatResponse($payment->fresh() ?? $payment, $lockedTx);
            });
        }

        // ReconciliationStatus::SUCCESS
        return DB::transaction(function () use ($payment, $transaction, $reconciliationResult): array {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::query()->where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            // CRITICAL GUARD 1: If transaction is already SUCCESS, DO NOT apply financial effect twice!
            if ($lockedTx->status === PaymentTransactionStatus::SUCCESS->value) {
                return $this->formatResponse($lockedPayment, $lockedTx);
            }

            // CRITICAL GUARD 2: Monotonicity check on payment aggregate before mutating financial state
            switch ($lockedTx->operation_type) {
                case PaymentOperationType::PURCHASE->value:
                case PaymentOperationType::AUTHORIZE->value:
                    if (! in_array($lockedPayment->status, [PaymentStatus::PENDING->value, PaymentStatus::AUTHORIZED->value], true)) {
                        throw InvalidPaymentTransitionException::forTransition($lockedPayment->status, $lockedTx->operation_type);
                    }
                    break;

                case PaymentOperationType::CAPTURE->value:
                    if ($lockedPayment->status !== PaymentStatus::AUTHORIZED->value) {
                        throw InvalidPaymentTransitionException::forTransition($lockedPayment->status, $lockedTx->operation_type);
                    }
                    break;

                case PaymentOperationType::REFUND->value:
                    if (! in_array($lockedPayment->status, [PaymentStatus::CAPTURED->value, PaymentStatus::PARTIALLY_REFUNDED->value], true)) {
                        throw InvalidPaymentTransitionException::forTransition($lockedPayment->status, $lockedTx->operation_type);
                    }
                    break;

                case PaymentOperationType::VOID->value:
                    if ($lockedPayment->status !== PaymentStatus::AUTHORIZED->value) {
                        throw InvalidPaymentTransitionException::forTransition($lockedPayment->status, $lockedTx->operation_type);
                    }
                    break;
            }

            $lockedTx->status = PaymentTransactionStatus::SUCCESS->value;
            $lockedTx->provider_reference = $reconciliationResult->providerReference ?? $lockedTx->provider_reference;
            $lockedTx->normalized_error_code = null;
            $lockedTx->action_payload = null;
            $lockedTx->save();

            // Apply exact operation-specific financial effect ONCE
            switch ($lockedTx->operation_type) {
                case PaymentOperationType::PURCHASE->value:
                    $lockedPayment->captured_amount_minor = $lockedTx->amount_minor;
                    $lockedPayment->status = PaymentStatus::CAPTURED->value;
                    $lockedPayment->captured_at = now();
                    $lockedPayment->save();

                    $this->orderPaymentSyncService->syncPaymentStatus(
                        tenantId: $lockedPayment->tenant_id,
                        orderId: $lockedPayment->order_id,
                        status: OrderPaymentStatus::PAID,
                        reason: 'Payment purchase reconciled successfully'
                    );

                    DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                        PaymentCaptured::dispatch($lockedPayment, $lockedTx);
                    });
                    break;

                case PaymentOperationType::AUTHORIZE->value:
                    $lockedPayment->authorized_amount_minor = $lockedTx->amount_minor;
                    $lockedPayment->status = PaymentStatus::AUTHORIZED->value;
                    $lockedPayment->authorized_at = now();
                    $lockedPayment->save();

                    $this->orderPaymentSyncService->syncPaymentStatus(
                        tenantId: $lockedPayment->tenant_id,
                        orderId: $lockedPayment->order_id,
                        status: OrderPaymentStatus::AUTHORIZED,
                        reason: 'Payment authorization reconciled successfully'
                    );

                    DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                        PaymentAuthorized::dispatch($lockedPayment, $lockedTx);
                    });
                    break;

                case PaymentOperationType::CAPTURE->value:
                    $lockedPayment->captured_amount_minor += $lockedTx->amount_minor;
                    if ($lockedPayment->captured_amount_minor === $lockedPayment->amount_minor) {
                        $lockedPayment->status = PaymentStatus::CAPTURED->value;
                        $lockedPayment->captured_at = now();
                        $lockedPayment->save();

                        $this->orderPaymentSyncService->syncPaymentStatus(
                            tenantId: $lockedPayment->tenant_id,
                            orderId: $lockedPayment->order_id,
                            status: OrderPaymentStatus::PAID,
                            reason: 'Payment capture reconciled successfully'
                        );
                    } else {
                        // Partial capture remains AUTHORIZED
                        $lockedPayment->save();
                    }

                    DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                        PaymentCaptured::dispatch($lockedPayment, $lockedTx);
                    });
                    break;

                case PaymentOperationType::REFUND->value:
                    $lockedPayment->refunded_amount_minor += $lockedTx->amount_minor;
                    if ($lockedPayment->refunded_amount_minor === $lockedPayment->captured_amount_minor) {
                        $lockedPayment->status = PaymentStatus::REFUNDED->value;
                        $lockedPayment->save();

                        $this->orderPaymentSyncService->syncPaymentStatus(
                            tenantId: $lockedPayment->tenant_id,
                            orderId: $lockedPayment->order_id,
                            status: OrderPaymentStatus::REFUNDED,
                            reason: 'Payment fully refunded upon reconciliation'
                        );

                        DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                            PaymentRefunded::dispatch($lockedPayment, $lockedTx);
                        });
                    } else {
                        $lockedPayment->status = PaymentStatus::PARTIALLY_REFUNDED->value;
                        $lockedPayment->save();

                        DB::afterCommit(function () use ($lockedPayment, $lockedTx): void {
                            PaymentPartiallyRefunded::dispatch($lockedPayment, $lockedTx);
                        });
                    }
                    break;

                case PaymentOperationType::VOID->value:
                    $lockedPayment->status = PaymentStatus::CANCELLED->value;
                    $lockedPayment->cancelled_at = now();
                    $lockedPayment->save();

                    $this->orderPaymentSyncService->syncPaymentStatus(
                        tenantId: $lockedPayment->tenant_id,
                        orderId: $lockedPayment->order_id,
                        status: OrderPaymentStatus::VOIDED,
                        reason: 'Payment void reconciled successfully'
                    );

                    DB::afterCommit(function () use ($lockedPayment): void {
                        PaymentCancelled::dispatch($lockedPayment, 'Void reconciled');
                    });
                    break;

                default:
                    throw new RuntimeException("Unsupported operation type [{$lockedTx->operation_type}] for reconciliation.");
            }

            return $this->formatResponse($lockedPayment, $lockedTx);
        });
    }

    /**
     * Format a safe client/domain response without exposing raw database integers.
     *
     * @return array<string, mixed>
     */
    public function formatResponse(Payment $payment, PaymentTransaction $transaction): array
    {
        return [
            'payment_uuid' => $payment->uuid,
            'transaction_uuid' => $transaction->uuid,
            'status' => $payment->status,
            'transaction_status' => $transaction->status,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'authorized_amount_minor' => $payment->authorized_amount_minor,
            'captured_amount_minor' => $payment->captured_amount_minor,
            'refunded_amount_minor' => $payment->refunded_amount_minor,
            'provider_reference' => $transaction->provider_reference,
            'action_type' => $transaction->action_type,
            'action_payload' => $transaction->action_payload,
            'normalized_error_code' => $transaction->normalized_error_code,
        ];
    }
}
