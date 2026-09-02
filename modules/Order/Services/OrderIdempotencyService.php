<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderIdempotencyServiceInterface;
use Modules\Order\Exceptions\IdempotencyFingerprintMismatchException;
use Modules\Order\Models\OrderOperationKey;
use RuntimeException;
use Throwable;

class OrderIdempotencyService implements OrderIdempotencyServiceInterface
{
    public function execute(
        int $tenantId,
        ?int $checkoutId,
        ?int $orderId,
        string $operationType,
        ?string $idempotencyKey,
        array $requestPayload,
        Closure $callback
    ): array {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return DB::transaction(function () use ($callback): array {
                /** @var array<string, mixed> $result */
                $result = $callback();

                return $result;
            });
        }

        $fingerprint = hash('sha256', (string) json_encode($requestPayload));

        $findClaim = function () use ($tenantId, $checkoutId, $orderId, $operationType, $idempotencyKey): ?OrderOperationKey {
            $query = OrderOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('operation_type', $operationType)
                ->where('idempotency_key', $idempotencyKey);

            if ($checkoutId !== null) {
                $query->where('checkout_id', $checkoutId);
            } else {
                $query->where('order_id', $orderId);
            }

            return $query->first();
        };

        // Step A: Durable claim resolution with race collision handling
        /** @var OrderOperationKey|null $claim */
        $claim = $findClaim();

        if ($claim === null) {
            try {
                $claim = DB::transaction(function () use ($tenantId, $checkoutId, $orderId, $operationType, $idempotencyKey, $fingerprint, $findClaim) {
                    $existing = $findClaim();
                    if ($existing !== null) {
                        return $existing;
                    }

                    return OrderOperationKey::create([
                        'tenant_id' => $tenantId,
                        'checkout_id' => $checkoutId,
                        'order_id' => $orderId,
                        'operation_type' => $operationType,
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => $fingerprint,
                        'status' => 'processing',
                        'lease_expires_at' => now()->addSeconds(30),
                    ]);
                });
            } catch (Throwable) {
                $claim = $findClaim();
            }
        }

        if ($claim === null) {
            throw new RuntimeException("Concurrent idempotency conflict for key [{$idempotencyKey}].");
        }

        if ($claim->request_hash !== $fingerprint) {
            throw IdempotencyFingerprintMismatchException::forOperation($operationType, $idempotencyKey);
        }

        if ($claim->status === 'completed') {
            /** @var array<string, mixed> $stored */
            $stored = (array) ($claim->response_payload ?? []);
            $stored['is_replay'] = true;
            unset($stored['guest_access_token']);

            return $stored;
        }

        // If another worker claimed processing, wait briefly for completion
        if ($claim->status === 'processing') {
            for ($i = 0; $i < 60; $i++) {
                usleep(50000); // 50ms (up to 3s)
                $polled = OrderOperationKey::find($claim->id);
                if ($polled !== null && $polled->status === 'completed') {
                    /** @var array<string, mixed> $stored */
                    $stored = (array) ($polled->response_payload ?? []);
                    $stored['is_replay'] = true;
                    unset($stored['guest_access_token']);

                    return $stored;
                }
            }
        }

        // Step B: Mutation + Completion in the SAME atomic transaction
        try {
            return DB::transaction(function () use ($claim, $callback) {
                /** @var OrderOperationKey $lockedClaim */
                $lockedClaim = OrderOperationKey::query()->where('id', $claim->id)->lockForUpdate()->firstOrFail();

                if ($lockedClaim->status === 'completed') {
                    /** @var array<string, mixed> $stored */
                    $stored = (array) ($lockedClaim->response_payload ?? []);
                    $stored['is_replay'] = true;
                    unset($stored['guest_access_token']);

                    return $stored;
                }

                /** @var array<string, mixed> $result */
                $result = $callback();

                // Sanitize response payload stored in order_operation_keys: NEVER persist plaintext guest token
                $sanitizedPayload = $result;
                unset($sanitizedPayload['guest_access_token']);
                $sanitizedPayload['is_replay'] = true;

                $lockedClaim->status = 'completed';
                $lockedClaim->response_payload = $sanitizedPayload;
                $lockedClaim->completed_at = now();
                $lockedClaim->save();

                return $result;
            });
        } catch (Throwable $e) {
            // Step C: On failure (mutation rolled back), record failure in isolated durable transaction
            try {
                DB::transaction(function () use ($claim, $e) {
                    $freshKey = OrderOperationKey::query()->where('id', $claim->id)->lockForUpdate()->first();
                    if ($freshKey !== null) {
                        $freshKey->status = 'failed';
                        $freshKey->error_payload = [
                            'error_class' => class_basename($e),
                            'error_code' => 'OPERATION_FAILED',
                            'retryable' => true,
                            'failed_at' => now()->toIso8601String(),
                        ];
                        $freshKey->save();
                    }
                });
            } catch (Throwable) {
                // Safe ignore isolation error
            }

            throw $e;
        }
    }
}
