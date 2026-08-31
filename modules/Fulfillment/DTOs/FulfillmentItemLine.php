<?php

declare(strict_types=1);

namespace Modules\Fulfillment\DTOs;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\ValueObjects\Dimension;
use Modules\Shipping\ValueObjects\Weight;

final readonly class FulfillmentItemLine
{
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public int $quantity,
        public MoneyValue $unitPrice,
        public Weight $unitWeight,
        public ?Dimension $dimensions = null,
        public ?int $shippingClassId = null,
        public bool $isShippable = true
    ) {}
}
