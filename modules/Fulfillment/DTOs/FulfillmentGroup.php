<?php

declare(strict_types=1);

namespace Modules\Fulfillment\DTOs;

use Modules\Shipping\ValueObjects\PackageCandidate;

final readonly class FulfillmentGroup
{
    /**
     * @param  array<int, FulfillmentItemLine>  $items
     * @param  array<int, PackageCandidate>  $packages
     */
    public function __construct(
        public string $groupKey,
        public string $fulfillmentMode, // own_stock, vendor_stock, dropship, 3pl, non_physical
        public ?int $inventorySourceId,
        public ?int $warehouseId,
        public array $items,
        public array $packages = [],
        public bool $isShippable = true,
        public ?string $splitReason = null
    ) {}
}
