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
    /**
     * Maximum attempts to wait for a concurrent in-flight operation.
     */
    private const int MAX_POLL_ATTEMPTS = 20;

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

        // 1. Initial check for already completed operation
        $existing = InventoryOperationKey::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $trimmedKey)
            ->where('operation_type', $operationType)
            ->first();

        if ($existing !== null && $existing->status === 'completed') {
            return $this->resolveStoredResult($existing);
        }

        // 2. Try to insert claim in standalone transaction or handle concurrent conflict
        $isClaimOwner = false;
        try {
            InventoryOperationKey::create([
                'tenant_id' => $tenantId,
                'idempotency_key' => $trimmedKey,
                'operation_type' => $operationType,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'status' => 'processing',
                'created_at' => now(),
            ]);
            $isClaimOwner = true;
        } catch (QueryException) {
            // Another process claimed the key concurrently
            $isClaimOwner = false;
        }

        // 3. If this process is NOT the claim owner, wait for the owner to complete
        if (! $isClaimOwner) {
            for ($attempt = 0; $attempt < self::MAX_POLL_ATTEMPTS; $attempt++) {
                usleep(self::POLL_SLEEP_MICROSECONDS);

                $record = InventoryOperationKey::query()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $trimmedKey)
                    ->where('operation_type', $operationType)
                    ->first();

                if ($record !== null && $record->status === 'completed') {
                    return $this->resolveStoredResult($record);
                }

                if ($record === null || $record->status === 'failed') {
                    // Owner failed or rolled back, try to claim again
                    return $this->execute($tenantId, $trimmedKey, $operationType, $resourceType, $resourceId, $callback);
                }
            }

            throw new \RuntimeException("Timeout waiting for concurrent idempotent operation [{$trimmedKey}] to complete.");
        }

        // 4. This process owns the claim: execute the mutation inside transaction
        try {
            $result = DB::transaction(function () use ($callback) {
                return $callback();
            });

            $storedResourceType = $resourceType;
            $storedResourceId = $resourceId;

            if ($result instanceof InventoryMovement) {
                $storedResourceType = 'inventory_movements';
                $storedResourceId = (string) $result->id;
            }

            InventoryOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $trimmedKey)
                ->where('operation_type', $operationType)
                ->update([
                    'status' => 'completed',
                    'resource_type' => $storedResourceType,
                    'resource_id' => $storedResourceId,
                    'response_payload' => is_array($result) || is_scalar($result) ? $result : ['success' => true],
                    'completed_at' => Carbon::now(),
                ]);

            return $result;
        } catch (\Throwable $e) {
            InventoryOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $trimmedKey)
                ->where('operation_type', $operationType)
                ->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            throw $e;
        }
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
}
