<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\DigitalDelivery\Exceptions\DigitalDeliveryException;
use Modules\DigitalDelivery\Models\LicenseKeyPool;

/**
 * Owner Delta §14: a license key is individually distinct, never modeled
 * as fungible physical Inventory. `SELECT ... FOR UPDATE SKIP LOCKED` is
 * the standard Postgres queue-pop idiom — safe under concurrency without a
 * broader table lock. Idempotent by assigned_order_item_id: a retried
 * OrderStatusChanged event for the same OrderItem returns the
 * already-assigned key, never allocates a second one. The partial unique
 * index (`uq_license_key_pools_one_order_item`) is the actual backstop —
 * this app-level check is only the first line of defense.
 */
final class LicenseKeyAllocationService
{
    public function allocate(int $tenantId, int $productId, int $orderItemId): LicenseKeyPool
    {
        return DB::transaction(function () use ($tenantId, $productId, $orderItemId): LicenseKeyPool {
            /** @var LicenseKeyPool|null $existing */
            $existing = LicenseKeyPool::where('tenant_id', $tenantId)
                ->where('assigned_order_item_id', $orderItemId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $query = LicenseKeyPool::where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->where('status', 'available');

            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            } else {
                $query->lockForUpdate();
            }

            /** @var LicenseKeyPool|null $candidate */
            $candidate = $query->orderBy('id')->first();

            if ($candidate === null) {
                throw new DigitalDeliveryException("No available license key for product [{$productId}] — out of stock.");
            }

            // Owner Delta §14: "the constraint, not the check, is the
            // actual source of truth" — a concurrent retry for this exact
            // OrderItem can race past the app-level pre-check above (both
            // readers observe "not yet assigned" before either commits).
            // The assignment itself runs in a NESTED transaction (a real
            // Postgres SAVEPOINT) so a unique-constraint hit rolls back
            // only this savepoint — not the whole allocation transaction —
            // letting the recovery lookup below still run.
            try {
                DB::transaction(function () use ($candidate, $orderItemId): void {
                    $candidate->status = 'assigned';
                    $candidate->assigned_order_item_id = $orderItemId;
                    $candidate->save();
                });
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'uq_license_key_pools_one_order_item')) {
                    /** @var LicenseKeyPool $winner */
                    $winner = LicenseKeyPool::where('tenant_id', $tenantId)
                        ->where('assigned_order_item_id', $orderItemId)
                        ->firstOrFail();

                    return $winner;
                }

                throw $e;
            }

            return $candidate;
        });
    }
}
