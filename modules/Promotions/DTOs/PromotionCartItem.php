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
        public int $quantity,
        public MoneyValue $unitPrice,
        public array $categoryIds = [],
        public ?int $brandId = null,
        public ?string $productType = null,
    ) {}

    public function getTotal(): MoneyValue
    {
        return $this->unitPrice->multiply($this->quantity);
    }
}
