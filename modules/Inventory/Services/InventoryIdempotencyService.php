<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryOperationKey;

class InventoryIdempotencyService
{
    /**
     * @param  callable(): mixed  $callback
     */
    public function execute(int $tenantId, ?string $idempotencyKey, string $operationType, string $resourceType, ?string $resourceId, callable $callback): mixed
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return $callback();
        }

        $trimmedKey = trim($idempotencyKey);

        // 1. Check existing record
        $existing = InventoryOperationKey::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $trimmedKey)
            ->where('operation_type', $operationType)
            ->first();

        if ($existing !== null) {
            if ($existing->status === 'completed') {
                if ($existing->resource_type === 'inventory_movements' && $existing->resource_id !== null) {
                    $movement = InventoryMovement::find((int) $existing->resource_id);
                    if ($movement !== null) {
                        return $movement;
                    }
                }

                return $existing->response_payload;
            }
            if ($existing->status === 'failed') {
                throw new \RuntimeException('Previous idempotent operation failed: '.($existing->error_message ?? 'Unknown error'));
            }
        }

        // 2. Atomic claim via DB transaction
        return DB::transaction(function () use ($tenantId, $trimmedKey, $operationType, $resourceType, $resourceId, $callback) {
            // Re-check under transaction with FOR UPDATE
            $existingLocked = InventoryOperationKey::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $trimmedKey)
                ->where('operation_type', $operationType)
                ->lockForUpdate()
                ->first();

            if ($existingLocked !== null) {
                if ($existingLocked->status === 'completed') {
                    if ($existingLocked->resource_type === 'inventory_movements' && $existingLocked->resource_id !== null) {
                        $movement = InventoryMovement::find((int) $existingLocked->resource_id);
                        if ($movement !== null) {
                            return $movement;
                        }
                    }

                    return $existingLocked->response_payload;
                }
                if ($existingLocked->status === 'failed') {
                    throw new \RuntimeException('Previous idempotent operation failed: '.($existingLocked->error_message ?? 'Unknown error'));
                }
            }

            // Create initial processing record
            $opKey = InventoryOperationKey::create([
                'tenant_id' => $tenantId,
                'idempotency_key' => $trimmedKey,
                'operation_type' => $operationType,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'status' => 'processing',
                'created_at' => now(),
            ]);

            try {
                $result = $callback();

                $storedResourceType = $resourceType;
                $storedResourceId = $resourceId;

                if ($result instanceof InventoryMovement) {
                    $storedResourceType = 'inventory_movements';
                    $storedResourceId = (string) $result->id;
                }

                $opKey->update([
                    'status' => 'completed',
                    'resource_type' => $storedResourceType,
                    'resource_id' => $storedResourceId,
                    'response_payload' => is_array($result) || is_scalar($result) ? $result : ['success' => true],
                    'completed_at' => Carbon::now(),
                ]);

                return $result;
            } catch (\Throwable $e) {
                $opKey->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }
}
