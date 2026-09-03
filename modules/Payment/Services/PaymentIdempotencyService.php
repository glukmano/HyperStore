<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Closure;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payment\Contracts\PaymentIdempotencyServiceInterface;
use Modules\Payment\Exceptions\PaymentIdempotencyConflictException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\PaymentOperationKey;
use Throwable;

class PaymentIdempotencyService implements PaymentIdempotencyServiceInterface
{
    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  Closure(PaymentOperationKey): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function execute(
        int $tenantId,
        int $orderId,
        ?int $paymentId,
        string $operationType,
        ?string $idempotencyKey,
        array $requestPayload,
        Closure $callback
    ): array {
        $actualKey = $idempotencyKey ?? 'auto_'.(string) Str::uuid();
        $requestHash = hash('sha256', (string) json_encode($requestPayload));

        $opKey = $this->acquireLease($tenantId, $orderId, $paymentId, $operationType, $actualKey, $requestHash);

        if ($opKey->status === 'completed' && $opKey->response_payload !== null) {
            return $opKey->response_payload;
        }

        try {
            $response = $callback($opKey);

            $opKey->status = 'completed';
            $opKey->response_payload = $response;
            $opKey->completed_at = now();
            $opKey->lease_expires_at = null;
            $opKey->save();

            return $response;
        } catch (PaymentReconciliationPendingException $e) {
            $opKey->status = 'unknown';
            $opKey->error_payload = ['error_code' => 'GATEWAY_TIMEOUT', 'retryable' => true];
            $opKey->lease_expires_at = null;
            $opKey->save();

            throw $e;
        } catch (Exception $e) {
            $opKey->status = 'failed';
            $opKey->error_payload = ['error_code' => 'OPERATION_FAILED', 'retryable' => false];
            $opKey->lease_expires_at = null;
            $opKey->save();

            throw $e;
        }
    }

    private function acquireLease(
        int $tenantId,
        int $orderId,
        ?int $paymentId,
        string $operationType,
        string $idempotencyKey,
        string $requestHash
    ): PaymentOperationKey {
        $findClaim = function () use ($tenantId, $orderId, $paymentId, $operationType, $idempotencyKey): ?PaymentOperationKey {
            $query = PaymentOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->where('operation_type', $operationType);

            if ($operationType === 'initiate_payment') {
                $query->where('order_id', $orderId);
            } else {
                $query->where('payment_id', $paymentId);
            }

            return $query->first();
        };

        $wasExisting = false;
        /** @var PaymentOperationKey|null $claim */
        $claim = $findClaim();

        if ($claim === null) {
            try {
                $claim = DB::transaction(function () use ($tenantId, $orderId, $paymentId, $operationType, $idempotencyKey, $requestHash, $findClaim): PaymentOperationKey {
                    $existing = $findClaim();
                    if ($existing !== null) {
                        return $existing;
                    }

                    return PaymentOperationKey::create([
                        'tenant_id' => $tenantId,
                        'idempotency_key' => $idempotencyKey,
                        'operation_type' => $operationType,
                        'order_id' => $orderId,
                        'payment_id' => $paymentId,
                        'request_hash' => $requestHash,
                        'status' => 'started',
                        'lease_expires_at' => now()->addMinutes(2),
                    ]);
                });
            } catch (Throwable) {
                $wasExisting = true;
                $claim = $findClaim();
            }
        } else {
            $wasExisting = true;
        }

        if ($claim === null) {
            throw PaymentIdempotencyConflictException::forConflict($idempotencyKey);
        }

        if ($claim->request_hash !== $requestHash) {
            throw PaymentIdempotencyConflictException::forConflict($idempotencyKey);
        }

        if ($claim->status === 'completed') {
            return $claim;
        }

        if ($claim->status === 'unknown') {
            // Re-entry allowed for reconciliation
            $claim->status = 'started';
            $claim->lease_expires_at = now()->addMinutes(2);
            $claim->save();

            return $claim;
        }

        if ($wasExisting && $claim->status === 'started') {
            for ($i = 0; $i < 60; $i++) {
                usleep(50000); // 50ms
                $polled = PaymentOperationKey::find($claim->id);
                if ($polled !== null && in_array($polled->status, ['completed', 'unknown'], true)) {
                    return $polled;
                }
            }

            throw new PaymentIdempotencyConflictException("Operation with idempotency key [{$idempotencyKey}] is already in progress.");
        }

        return $claim;
    }
}
