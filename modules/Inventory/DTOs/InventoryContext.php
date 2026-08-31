<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class InventoryContext
{
    public function __construct(
        public int $tenantId,
        public ?int $storeId = null,
        public ?int $marketId = null,
        public ?int $channelId = null,
        public ?int $customerGroupId = null,
    ) {}
}
