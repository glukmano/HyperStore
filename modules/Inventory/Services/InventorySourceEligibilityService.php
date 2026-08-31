<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventorySource;

class InventorySourceEligibilityService
{
    /**
     * @return array<int, int> List of eligible inventory_source IDs
     */
    public function getEligibleSourceIds(InventoryContext $context): array
    {
        $sourceQuery = InventorySource::query()
            ->where('tenant_id', $context->tenantId)
            ->where('status', 'active')
            ->orderByDesc('priority');

        if ($context->storeId !== null) {
            $sourceQuery->where(function ($q) use ($context) {
                $q->whereDoesntHave('stores')
                    ->orWhereHas('stores', fn ($sq) => $sq->where('stores.id', $context->storeId));
            });
        }

        if ($context->marketId !== null) {
            $sourceQuery->where(function ($q) use ($context) {
                $q->whereDoesntHave('markets')
                    ->orWhereHas('markets', fn ($mq) => $mq->where('markets.id', $context->marketId));
            });
        }

        if ($context->channelId !== null) {
            $sourceQuery->where(function ($q) use ($context) {
                $q->whereDoesntHave('channels')
                    ->orWhereHas('channels', fn ($cq) => $cq->where('channels.id', $context->channelId));
            });
        }

        $sources = $sourceQuery->get();
        $eligibleIds = [];

        foreach ($sources as $source) {
            if ($source->isStale()) {
                continue; // Stale external feed is excluded from ATS and reservation
            }
            $eligibleIds[] = $source->id;
        }

        return $eligibleIds;
    }
}
