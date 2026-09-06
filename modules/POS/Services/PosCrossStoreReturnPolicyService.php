<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use App\Core\Stores\Models\StoreUser;
use Modules\Inventory\Models\InventorySource;
use Modules\POS\Exceptions\CrossStoreReturnNotAllowedException;
use Modules\POS\Models\PosCrossStoreReturnPolicy;

/**
 * Owner Delta §8: cross-store returns are policy-gated, not unconditional.
 * When enabled, the cashier must be authorized for the receiving Store and
 * the receiving InventorySource must belong to that Store. Not a Phase-23
 * feature flag — a small POS-owned config row per Tenant.
 */
final class PosCrossStoreReturnPolicyService
{
    public function assertAllowed(
        int $tenantId,
        int $originalStoreId,
        int $receivingStoreId,
        int $receivingInventorySourceId,
        int $cashierUserId
    ): void {
        if ($originalStoreId === $receivingStoreId) {
            return;
        }

        $policy = PosCrossStoreReturnPolicy::forTenant($tenantId);
        if (! $policy->cross_store_returns_enabled) {
            throw new CrossStoreReturnNotAllowedException(
                "Cross-store returns are disabled for Tenant [{$tenantId}] — this return must be processed at the original Store [{$originalStoreId}]."
            );
        }

        $cashierAuthorized = StoreUser::query()
            ->where('store_id', $receivingStoreId)
            ->where('user_id', $cashierUserId)
            ->where('is_active', true)
            ->exists();

        if (! $cashierAuthorized) {
            throw new CrossStoreReturnNotAllowedException(
                "Cashier [{$cashierUserId}] is not authorized for the receiving Store [{$receivingStoreId}]."
            );
        }

        $sourceBelongsToReceivingStore = InventorySource::query()
            ->where('id', $receivingInventorySourceId)
            ->whereHas('stores', function ($q) use ($receivingStoreId): void {
                $q->where('stores.id', $receivingStoreId);
            })
            ->exists();

        if (! $sourceBelongsToReceivingStore) {
            throw new CrossStoreReturnNotAllowedException(
                "InventorySource [{$receivingInventorySourceId}] does not belong to the receiving Store [{$receivingStoreId}]."
            );
        }
    }
}
