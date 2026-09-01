<?php

declare(strict_types=1);

namespace Modules\Promotions\DTOs;

use Modules\Pricing\ValueObjects\MoneyValue;

final class PromotionResult
{
    /**
     * @param  array<int, DiscountLine>  $discounts
     * @param  array<string, mixed>  $entitlements
     * @param  list<PromotionBenefitDTO>  $benefits
     * @param  list<int>  $appliedPromotionIds
     */
    public function __construct(
        public MoneyValue $subtotal,
        public MoneyValue $totalDiscount,
        public MoneyValue $finalTotal,
        public array $discounts = [],
        public array $entitlements = [],
        public array $benefits = [],
        public array $appliedPromotionIds = [],
    ) {}
}
