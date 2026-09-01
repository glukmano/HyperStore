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
     * Executes an operation idempotently with durable isolated failure recording.
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

        // 1. Durable lease / claim check
        $opKey = DB::transaction(function () use ($tenantId, $cartId, $checkoutSessionId, $operationType, $idempotencyKey, $fingerprint) {
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

        if ($opKey->status === 'completed') {
            return (array) ($opKey->response_payload ?? []);
        }

        // 2. Execute mutation callback
        try {
            /** @var array<string, mixed> $result */
            $result = $callback();

            // Mark completed
            DB::transaction(function () use ($opKey, $result) {
                $freshKey = CheckoutOperationKey::query()->where('id', $opKey->id)->lockForUpdate()->first();
                if ($freshKey !== null) {
                    $freshKey->status = 'completed';
                    $freshKey->response_payload = $result;
                    $freshKey->save();
                }
            });

            return $result;
        } catch (Throwable $e) {
            // Record failure in isolated transaction (no raw exception message, no PII/tokens)
            try {
                DB::transaction(function () use ($opKey, $e) {
                    $freshKey = CheckoutOperationKey::query()->where('id', $opKey->id)->lockForUpdate()->first();
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
