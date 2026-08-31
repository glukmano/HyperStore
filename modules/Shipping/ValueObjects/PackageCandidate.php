<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

final readonly class PackageCandidate
{
    /**
     * @param  array<int, array{product_id: int, variant_id: ?int, quantity: int, weight: Weight, shipping_class_id: ?int}>  $items
     */
    public function __construct(
        public array $items,
        public Weight $totalWeight,
        public ?Dimension $dimensions = null,
        public ?int $packageTypeId = null,
        public ?int $inventorySourceId = null
    ) {}
}
