<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\ValueObjects\Quantity;

final readonly class AvailabilityResultDTO
{
    /**
     * @param  array<int, array{source_id: int, source_name: string, available: Quantity}>  $sourceBreakdown
     */
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public Quantity $availableQuantity,
        public bool $isInStock,
        public bool $isBackorderable,
        public bool $isLowStock,
        public string $stockStatus, // in_stock, low_stock, out_of_stock, backorder, untracked
        public array $sourceBreakdown = [],
    ) {}
}
