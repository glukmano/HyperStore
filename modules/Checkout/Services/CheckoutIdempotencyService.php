<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Modules\Checkout\Models\CheckoutOperationKey;
use RuntimeException;
use Throwable;

class CheckoutIdempotencyService
{
    /**
     * Executes an operation idempotently with durable isolated failure recording and atomic mutation completion.
     *
     * @param  array<string, mixed>  $requestPayload
     * @return array<string, mixed>
     */
    public function execute(
        int $tenantId,
        ?int $cartId,
        ?int $checkoutSessionId,
        string $operationType,
        ?string $idempotencyKey,
        array $requestPayload,
        Closure $callback
    ): array {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            /** @var array<string, mixed> $result */
            $result = $callback();

            return $result;
        }

        $fingerprint = hash('sha256', (string) json_encode($requestPayload));

        // Step A: Durable claim transaction
        /** @var CheckoutOperationKey $claim */
        $claim = DB::transaction(function () use ($tenantId, $cartId, $checkoutSessionId, $operationType, $idempotencyKey, $fingerprint) {
            $query = CheckoutOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('operation_type', $operationType)
                ->where('idempotency_key', $idempotencyKey);

            if ($cartId !== null) {
                $query->where('cart_id', $cartId);
            } else {
                $query->where('checkout_session_id', $checkoutSessionId);
            }

            /** @var CheckoutOperationKey|null $existing */
            $existing = $query->lockForUpdate()->first();

            if ($existing !== null) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new RuntimeException("Idempotency key [{$idempotencyKey}] was previously used with a different request payload.");
                }

                if ($existing->status === 'completed') {
                    return $existing;
                }

                if ($existing->status === 'processing') {
                    // Check for abandoned lease (older than 30s)
                    if ($existing->lease_expires_at && $existing->lease_expires_at->isFuture()) {
                        throw new RuntimeException("Operation with idempotency key [{$idempotencyKey}] is currently processing.");
                    }
                    // Take over abandoned lease
                    $existing->lease_expires_at = now()->addSeconds(30);
                    $existing->save();

                    return $existing;
                }
            }

            // Create new claim
            return CheckoutOperationKey::create([
                'tenant_id' => $tenantId,
                'cart_id' => $cartId,
                'checkout_session_id' => $checkoutSessionId,
                'operation_type' => $operationType,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'status' => 'processing',
                'lease_expires_at' => now()->addSeconds(30),
            ]);
        });

        if ($claim->status === 'completed') {
            return (array) ($claim->response_payload ?? []);
        }

        // Step B: Mutation + Completion in the SAME atomic transaction
        try {
            return DB::transaction(function () use ($claim, $callback) {
                /** @var CheckoutOperationKey $lockedClaim */
                $lockedClaim = CheckoutOperationKey::query()->where('id', $claim->id)->lockForUpdate()->firstOrFail();

                /** @var array<string, mixed> $result */
                $result = $callback();

                $lockedClaim->status = 'completed';
                $lockedClaim->response_payload = $result;
                $lockedClaim->completed_at = now();
                $lockedClaim->save();

                return $result;
            });
        } catch (Throwable $e) {
            // Step C: On failure (mutation rolled back), record failure in isolated durable transaction
            try {
                DB::transaction(function () use ($claim, $e) {
                    $freshKey = CheckoutOperationKey::query()->where('id', $claim->id)->lockForUpdate()->first();
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
