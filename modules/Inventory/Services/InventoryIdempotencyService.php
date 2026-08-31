<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\QueryException;
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

        // 1. First check if completed key exists
        $existing = InventoryOperationKey::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $trimmedKey)
            ->where('operation_type', $operationType)
            ->first();

        if ($existing !== null) {
            if ($existing->resource_type === 'inventory_movements' && $existing->resource_id !== null) {
                $movement = InventoryMovement::find((int) $existing->resource_id);
                if ($movement !== null) {
                    return $movement;
                }
            }

            return $existing->response_payload;
        }

        // 2. Atomic claim via DB transaction
        return DB::transaction(function () use ($tenantId, $trimmedKey, $operationType, $resourceType, $resourceId, $callback) {
            $result = $callback();

            $storedResourceType = $resourceType;
            $storedResourceId = $resourceId;

            if ($result instanceof InventoryMovement) {
                $storedResourceType = 'inventory_movements';
                $storedResourceId = (string) $result->id;
            }

            try {
                InventoryOperationKey::create([
                    'tenant_id' => $tenantId,
                    'idempotency_key' => $trimmedKey,
                    'operation_type' => $operationType,
                    'resource_type' => $storedResourceType,
                    'resource_id' => $storedResourceId,
                    'response_payload' => is_array($result) || is_scalar($result) ? $result : ['success' => true],
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                // If concurrent race inserted key, fetch existing
                $existing = InventoryOperationKey::query()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $trimmedKey)
                    ->where('operation_type', $operationType)
                    ->first();

                if ($existing !== null) {
                    if ($existing->resource_type === 'inventory_movements' && $existing->resource_id !== null) {
                        return InventoryMovement::find((int) $existing->resource_id);
                    }

                    return $existing->response_payload;
                }
                throw $e;
            }

            return $result;
        });
    }
}
