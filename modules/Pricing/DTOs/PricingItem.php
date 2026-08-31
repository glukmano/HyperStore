<?php

declare(strict_types=1);

namespace Modules\Pricing\DTOs;

final class PricingItem
{
    public function __construct(
        public int $productId,
        public ?int $variantId = null,
        public int $quantity = 1,
    ) {}
}
