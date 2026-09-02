<?php

declare(strict_types=1);

namespace Modules\Promotions\DTOs;

use Modules\Pricing\ValueObjects\MoneyValue;

final class PromotionCartItem
{
    /**
     * @param  array<int, int>  $categoryIds
     */
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public int|string $quantity,
        public MoneyValue $unitPrice,
        public array $categoryIds = [],
        public ?int $brandId = null,
        public ?string $productType = null,
        public ?int $cartLineId = null,
    ) {}

    public function getTotal(): MoneyValue
    {
        return $this->unitPrice->multiply((string) $this->quantity);
    }
}
