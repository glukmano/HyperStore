<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class RateBreakdown
{
    /**
     * @param  array<string, mixed>  $appliedPromotionBenefits
     */
    public function __construct(
        public MoneyValue $baseRate,
        public MoneyValue $perItemAmount,
        public MoneyValue $perWeightAmount,
        public MoneyValue $handlingFee,
        public MoneyValue $carrierMarkup,
        public MoneyValue $promotionDiscount,
        public MoneyValue $finalAmount,
        public array $appliedPromotionBenefits = []
    ) {}
}
