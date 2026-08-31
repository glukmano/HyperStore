<?php

declare(strict_types=1);

namespace Modules\Promotions\Actions;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\PromotionActionInterface;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;

class FreeShippingAction implements PromotionActionInterface
{
    public function getType(): string
    {
        return 'free_shipping';
    }

    public function apply(Promotion $promotion, PromotionContext $context, array $parameters, MoneyValue $currentTotal): ?DiscountLine
    {
        // Free shipping returns an entitlement benefit (0 monetary cart discount line with entitlement metadata)
        return new DiscountLine(
            promotionId: $promotion->id,
            promotionCode: $promotion->code,
            description: 'Free Standard Shipping Benefit',
            discountAmount: MoneyValue::zero($context->currency)
        );
    }
}
