<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryOperationKey;

class InventoryIdempotencyService
{
    private const int LEASE_SECONDS = 30;

    private const int MAX_POLL_ATTEMPTS = 30;

    private const int POLL_SLEEP_MICROSECONDS = 100000; // 100ms

    /**
     * @param  callable(): mixed  $callback
     */
    public function execute(int $tenantId, ?string $idempotencyKey, string $operationType, string $resourceType, ?string $resourceId, callable $callback): mixed
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return $callback();
        }

        $trimmedKey = trim($idempotencyKey);

        // Loop handles initial claim, waiting on valid lease, or takeover on expired/failed lease without unbounded recursion
        for ($iteration = 0; $iteration < 3; $iteration++) {
            // 1. Check existing record
            $existing = InventoryOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $trimmedKey)
                ->where('operation_type', $operationType)
                ->first();

            if ($existing !== null && $existing->status === 'completed') {
                return $this->resolveStoredResult($existing);
            }

            // 2. If record exists and is processing with an active lease -> wait/poll
            if ($existing !== null && $existing->status === 'processing') {
                $leaseExpires = $existing->lease_expires_at !== null ? Carbon::parse($existing->lease_expires_at) : null;
                $isLeaseActive = $leaseExpires !== null && $leaseExpires->isFuture();

                if ($isLeaseActive) {
                    $polledResult = $this->pollForCompletion($tenantId, $trimmedKey, $operationType);
                    if ($polledResult !== null) {
                        return $polledResult;
                    }
                }
            }

            // 3. If record exists in failed state or processing with expired lease -> attempt atomic takeover (CAS)
            if ($existing !== null) {
                $affected = InventoryOperationKey::query()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $trimmedKey)
                    ->where('operation_type', $operationType)
                    ->where(function ($q) {
                        $q->where('status', 'failed')
                            ->orWhere(function ($sq) {
                                $sq->where('status', 'processing')
                                    ->where(function ($lq) {
                                        $lq->whereNull('lease_expires_at')
                                            ->orWhere('lease_expires_at', '<=', Carbon::now());
                                    });
                            });
                    })
                    ->update([
                        'status' => 'processing',
                        'lease_expires_at' => Carbon::now()->addSeconds(self::LEASE_SECONDS),
                        'error_message' => null,
                    ]);

                if ($affected === 1) {
                    return $this->runMutationAndComplete($tenantId, $trimmedKey, $operationType, $resourceType, $resourceId, $callback);
                }

                // Another process took over -> loop again to check/poll
                continue;
            }

            // 4. Record does not exist -> attempt initial claim
            try {
                InventoryOperationKey::create([
                    'tenant_id' => $tenantId,
                    'idempotency_key' => $trimmedKey,
                    'operation_type' => $operationType,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'status' => 'processing',
                    'lease_expires_at' => Carbon::now()->addSeconds(self::LEASE_SECONDS),
                    'created_at' => now(),
                ]);

                return $this->runMutationAndComplete($tenantId, $trimmedKey, $operationType, $resourceType, $resourceId, $callback);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e)) {
                    throw $e; // Rethrow non-uniqueness DB errors (connection failure, column errors, etc.)
                }

                // Unique collision -> another process claimed concurrently, loop to wait/poll
            }
        }

        // Final poll attempt if all iterations exhausted
        $finalRecord = InventoryOperationKey::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $trimmedKey)
            ->where('operation_type', $operationType)
            ->first();

        if ($finalRecord !== null && $finalRecord->status === 'completed') {
            return $this->resolveStoredResult($finalRecord);
        }

        throw new \RuntimeException("Unable to acquire or resolve idempotency claim for key [{$trimmedKey}].");
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function runMutationAndComplete(int $tenantId, string $key, string $opType, string $resType, ?string $resId, callable $callback): mixed
    {
        try {
            $result = DB::transaction(function () use ($callback) {
                return $callback();
            });

            $storedResourceType = $resType;
            $storedResourceId = $resId;

            if ($result instanceof InventoryMovement) {
                $storedResourceType = 'inventory_movements';
                $storedResourceId = (string) $result->id;
            }

            InventoryOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $key)
                ->where('operation_type', $opType)
                ->update([
                    'status' => 'completed',
                    'resource_type' => $storedResourceType,
                    'resource_id' => $storedResourceId,
                    'response_payload' => is_array($result) || is_scalar($result) ? $result : ['success' => true],
                    'completed_at' => Carbon::now(),
                ]);

            return $result;
        } catch (\Throwable $e) {
            // Mark claim failed so subsequent retries can safely recover and retry
            InventoryOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $key)
                ->where('operation_type', $opType)
                ->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

            throw $e;
        }
    }

    private function pollForCompletion(int $tenantId, string $key, string $opType): mixed
    {
        for ($attempt = 0; $attempt < self::MAX_POLL_ATTEMPTS; $attempt++) {
            usleep(self::POLL_SLEEP_MICROSECONDS);

            $record = InventoryOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $key)
                ->where('operation_type', $opType)
                ->first();

            if ($record !== null && $record->status === 'completed') {
                return $this->resolveStoredResult($record);
            }

            if ($record === null || $record->status === 'failed') {
                return null; // Return null so outer loop can trigger takeover/retry
            }

            // If lease expired while polling, break to allow takeover
            if ($record->lease_expires_at !== null && Carbon::parse($record->lease_expires_at)->isPast()) {
                return null;
            }
        }

        return null;
    }

    private function resolveStoredResult(InventoryOperationKey $record): mixed
    {
        if ($record->resource_type === 'inventory_movements' && $record->resource_id !== null) {
            $movement = InventoryMovement::find((int) $record->resource_id);
            if ($movement !== null) {
                return $movement;
            }
        }

        return $record->response_payload;
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        // PostgreSQL 23505 or standard 23000
        return $code === '23505'
            || $sqlState === '23505'
            || $code === '23000'
            || str_contains($e->getMessage(), 'unique')
            || str_contains($e->getMessage(), 'Duplicate');
    }
}
