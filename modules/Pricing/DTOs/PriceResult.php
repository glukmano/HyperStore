<?php

declare(strict_types=1);

namespace Modules\Pricing\DTOs;

use Modules\Pricing\ValueObjects\MoneyValue;

final class PriceResult
{
    /**
     * @param  array<int, string>  $appliedRules
     */
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public MoneyValue $unitPrice,
        public ?MoneyValue $compareAtPrice,
        public ?MoneyValue $costPrice,
        public ?int $appliedPriceBookId,
        public ?int $appliedTierMinQuantity,
        public array $appliedRules = [],
    ) {}
}
