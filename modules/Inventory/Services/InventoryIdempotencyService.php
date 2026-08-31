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
                    throw $e; // Strict rethrow of non-uniqueness DB errors (connection failure, column errors, syntax errors)
                }

                // Strict unique collision -> another process claimed concurrently, loop to wait/poll
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
     * Executes domain mutation and marks idempotency completion inside ONE atomic database transaction.
     *
     * Invariant:
     * Either mutation commits AND idempotency status='completed' commits atomically,
     * OR mutation rolls back AND idempotency status is NOT marked completed.
     *
     * @param  callable(): mixed  $callback
     */
    private function runMutationAndComplete(int $tenantId, string $key, string $opType, string $resType, ?string $resId, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($tenantId, $key, $opType, $resType, $resId, $callback) {
                // 1. Lock and verify ownership of the idempotency claim inside the outer transaction
                /** @var InventoryOperationKey $opKey */
                $opKey = InventoryOperationKey::query()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $key)
                    ->where('operation_type', $opType)
                    ->lockForUpdate()
                    ->firstOrFail();

                // 2. Execute the inventory mutation (nested DB::transactions inside callback participate via savepoints)
                $result = $callback();

                $storedResourceType = $resType;
                $storedResourceId = $resId;

                if ($result instanceof InventoryMovement) {
                    $storedResourceType = 'inventory_movements';
                    $storedResourceId = (string) $result->id;
                }

                // 3. Atomically transition claim status to completed and record response payload
                $opKey->update([
                    'status' => 'completed',
                    'resource_type' => $storedResourceType,
                    'resource_id' => $storedResourceId,
                    'response_payload' => is_array($result) || is_scalar($result) ? $result : ['success' => true],
                    'completed_at' => Carbon::now(),
                ]);

                return $result;
            });
        } catch (\Throwable $e) {
            // Transaction rolled back: mutation and completed status were NOT committed.
            // Safely mark failed in a separate transaction so subsequent retries can acquire the claim.
            try {
                InventoryOperationKey::query()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $key)
                    ->where('operation_type', $opType)
                    ->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ]);
            } catch (\Throwable) {
                // Ignore secondary errors if DB is unreachable
            }

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
                return null;
            }

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

    /**
     * Strictly verifies SQLSTATE 23505 (PostgreSQL unique violation) or SQLite 23000 error code 19.
     * Never falls back to message-text substring matching.
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // PostgreSQL SQLSTATE 23505
        if ($code === '23505' || $sqlState === '23505') {
            return true;
        }

        // SQLite SQLSTATE 23000 with error code 19 (SQLITE_CONSTRAINT_UNIQUE)
        if ($sqlState === '23000' && $driverCode === 19) {
            return true;
        }

        return false;
    }
}
