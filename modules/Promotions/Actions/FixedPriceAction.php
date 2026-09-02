<?php

declare(strict_types=1);

namespace Modules\Promotions\Actions;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\PromotionItemFilterActionInterface;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;

class FixedPriceAction implements PromotionItemFilterActionInterface
{
    public function getType(): string
    {
        return 'fixed_price';
    }

    public function isItemTargeted(PromotionCartItem $item, array $parameters): bool
    {
        $targetProductId = (int) ($parameters['product_id'] ?? 0);

        return $targetProductId > 0 && $item->productId === $targetProductId;
    }

    public function apply(Promotion $promotion, PromotionContext $context, array $parameters, MoneyValue $currentTotal): ?DiscountLine
    {
        $targetPriceMinor = (int) ($parameters['amount_minor'] ?? 0);
        $targetProductId = (int) ($parameters['product_id'] ?? 0);

        $discountMinor = 0;
        foreach ($context->items as $item) {
            if ($this->isItemTargeted($item, $parameters) && $item->unitPrice->getMinorAmount() > $targetPriceMinor) {
                $diffPerUnit = $item->unitPrice->getMinorAmount() - $targetPriceMinor;
                $diffMoney = MoneyValue::fromMinor($diffPerUnit, $context->currency);
                $discountMinor += $diffMoney->multiply((string) $item->quantity)->getMinorAmount();
            }
        }

        if ($discountMinor <= 0) {
            return null;
        }

        $discount = MoneyValue::fromMinor($discountMinor, $context->currency);

        return new DiscountLine(
            promotionId: $promotion->id,
            promotionCode: $promotion->code,
            description: 'Fixed promotional item price',
            discountAmount: $discount
        );
    }
}
