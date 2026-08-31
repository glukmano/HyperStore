<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryReservationAllocation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

class InventoryReconciliationService
{
    /**
     * @return array{
     *     is_clean: bool,
     *     total_stock_items: int,
     *     balance_discrepancies: array<int, array{stock_item_id: int, on_hand: string, expected_on_hand: string, drift: string}>,
     *     reservation_discrepancies: array<int, array{stock_item_id: int, reserved: string, expected_reserved: string, drift: string}>
     * }
     */
    public function reconcile(int $tenantId): array
    {
        $stockItems = StockItem::query()->where('tenant_id', $tenantId)->get();
        $balanceDiscrepancies = [];
        $reservationDiscrepancies = [];

        foreach ($stockItems as $item) {
            // 1. Audit On-Hand against Movements Sum
            $movementsSum = InventoryMovement::query()
                ->where('stock_item_id', $item->id)
                ->sum('quantity_delta');

            $expectedOnHand = Quantity::fromString((string) ($movementsSum ?? 0));
            $actualOnHand = Quantity::fromString((string) $item->on_hand);

            if (! $actualOnHand->equals($expectedOnHand)) {
                $balanceDiscrepancies[] = [
                    'stock_item_id' => $item->id,
                    'on_hand' => $actualOnHand->toString(),
                    'expected_on_hand' => $expectedOnHand->toString(),
                    'drift' => $actualOnHand->subtract($expectedOnHand)->toString(),
                ];
            }

            // 2. Audit Reserved against Active Allocations Sum
            $activeAllocationsSum = InventoryReservationAllocation::query()
                ->where('stock_item_id', $item->id)
                ->whereHas('reservation', fn ($q) => $q->where('status', 'active'))
                ->sum('quantity');

            $expectedReserved = Quantity::fromString((string) ($activeAllocationsSum ?? 0));
            $actualReserved = Quantity::fromString((string) $item->reserved);

            if (! $actualReserved->equals($expectedReserved)) {
                $reservationDiscrepancies[] = [
                    'stock_item_id' => $item->id,
                    'reserved' => $actualReserved->toString(),
                    'expected_reserved' => $expectedReserved->toString(),
                    'drift' => $actualReserved->subtract($expectedReserved)->toString(),
                ];
            }
        }

        $isClean = empty($balanceDiscrepancies) && empty($reservationDiscrepancies);

        return [
            'is_clean' => $isClean,
            'total_stock_items' => $stockItems->count(),
            'balance_discrepancies' => $balanceDiscrepancies,
            'reservation_discrepancies' => $reservationDiscrepancies,
        ];
    }
}
