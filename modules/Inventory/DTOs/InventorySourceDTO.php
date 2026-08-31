<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use DateTimeImmutable;

final readonly class InventorySourceDTO
{
    /**
     * @param  int[]  $storeIds
     * @param  int[]  $marketIds
     * @param  int[]  $channelIds
     */
    public function __construct(
        public int $id,
        public int $tenantId,
        public ?int $warehouseId,
        public string $sourceType,
        public string $code,
        public string $name,
        public string $status,
        public int $priority,
        public array $storeIds = [],
        public array $marketIds = [],
        public array $channelIds = [],
        public bool $isStale = false,
        public ?DateTimeImmutable $lastSyncedAt = null,
    ) {}
}
