<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class ShippingRateRequest
{
    /**
     * @param  array<int, array{product_id: int, variant_id: ?int, quantity: int, unit_price: MoneyValue, unit_weight: Weight, dimensions: ?Dimension, shipping_class_id: ?int, is_shippable: bool, inventory_source_id: ?int}>  $lines
     * @param  array<int, mixed>  $promotionBenefits
     */
    public function __construct(
        public ShippingContext $context,
        public ShippingDestination $destination,
        public array $lines,
        public array $promotionBenefits = []
    ) {}
}
