<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Checkout\Models\CheckoutOperationKey;
use RuntimeException;

class CheckoutIdempotencyService
{
    private const LEASE_SECONDS = 60;

    /**
     * Executes an operation idempotently with aggregate-scoped uniqueness.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    /**
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
        callable $callback
    ): array {
        // Enforce mutually exclusive scope columns
        if (($cartId !== null && $checkoutSessionId !== null) || ($cartId === null && $checkoutSessionId === null)) {
            throw new InvalidArgumentException('Idempotency operation must provide exactly one of cartId or checkoutSessionId.');
        }

        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return $callback();
        }

        $trimmedKey = trim($idempotencyKey);
        $requestFingerprint = hash('sha256', (string) json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 1. Check existing record
        $query = CheckoutOperationKey::query()
            ->where('tenant_id', $tenantId)
            ->where('operation_type', $operationType)
            ->where('idempotency_key', $trimmedKey);

        if ($cartId !== null) {
            $query->where('cart_id', $cartId)->whereNull('checkout_session_id');
        } else {
            $query->where('checkout_session_id', $checkoutSessionId)->whereNull('cart_id');
        }

        /** @var CheckoutOperationKey|null $existing */
        $existing = $query->first();

        if ($existing !== null) {
            if ($existing->status === 'completed') {
                if ($existing->request_fingerprint !== $requestFingerprint) {
                    throw new RuntimeException("Idempotency key [{$trimmedKey}] was previously used with a different request payload.");
                }

                return (array) ($existing->response_payload ?? []);
            }

            if ($existing->status === 'processing') {
                if ($existing->lease_expires_at !== null && Carbon::parse($existing->lease_expires_at)->isFuture()) {
                    throw new RuntimeException("A concurrent request with idempotency key [{$trimmedKey}] is currently processing.");
                }
            }
        }

        // 2. Acquire or update claim and run inside atomic outer transaction
        return DB::transaction(function () use ($tenantId, $cartId, $checkoutSessionId, $operationType, $trimmedKey, $requestFingerprint, $callback) {
            /** @var CheckoutOperationKey $opKey */
            $opKey = CheckoutOperationKey::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'cart_id' => $cartId,
                    'checkout_session_id' => $checkoutSessionId,
                    'operation_type' => $operationType,
                    'idempotency_key' => $trimmedKey,
                ],
                [
                    'request_fingerprint' => $requestFingerprint,
                    'status' => 'processing',
                    'lease_expires_at' => Carbon::now()->addSeconds(self::LEASE_SECONDS),
                ]
            );

            try {
                $result = $callback();

                $opKey->update([
                    'status' => 'completed',
                    'response_payload' => $result,
                    'completed_at' => Carbon::now(),
                ]);

                return $result;
            } catch (\Throwable $e) {
                $opKey->update([
                    'status' => 'failed',
                    'error_payload' => ['error' => $e->getMessage()],
                ]);
                throw $e;
            }
        });
    }
}
