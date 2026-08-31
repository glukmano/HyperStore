<?php

declare(strict_types=1);

namespace Modules\Promotions\Actions;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\PromotionActionInterface;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;

class PercentageDiscountAction implements PromotionActionInterface
{
    public function getType(): string
    {
        return 'percentage_discount';
    }

    public function apply(Promotion $promotion, PromotionContext $context, array $parameters, MoneyValue $currentTotal): ?DiscountLine
    {
        $percent = $parameters['percentage'] ?? 0;
        if ($percent <= 0 || $currentTotal->isZero()) {
            return null;
        }

        $discount = $currentTotal->percentage($percent);

        return new DiscountLine(
            promotionId: $promotion->id,
            promotionCode: $promotion->code,
            description: "{$percent}% off cart total",
            discountAmount: $discount
        );
    }
}
