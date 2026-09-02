<?php

declare(strict_types=1);

namespace Modules\Promotions\Actions;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\PromotionItemFilterActionInterface;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;

class BuyXGetYAction implements PromotionItemFilterActionInterface
{
    public function getType(): string
    {
        return 'buy_x_get_y';
    }

    public function isItemTargeted(PromotionCartItem $item, array $parameters): bool
    {
        $targetProductId = isset($parameters['product_id']) ? (int) $parameters['product_id'] : null;

        return $targetProductId === null || $item->productId === $targetProductId;
    }

    public function apply(Promotion $promotion, PromotionContext $context, array $parameters, MoneyValue $currentTotal): ?DiscountLine
    {
        $buyQty = (int) ($parameters['buy_quantity'] ?? 2);
        $getQty = (int) ($parameters['get_free_quantity'] ?? 1);

        $eligibleCount = 0;
        $unitPrice = null;

        foreach ($context->items as $item) {
            if ($this->isItemTargeted($item, $parameters)) {
                $eligibleCount += (int) $item->quantity;
                $unitPrice = $item->unitPrice;
            }
        }

        if ($unitPrice === null || $eligibleCount < ($buyQty + $getQty)) {
            return null;
        }

        $freeSets = (int) floor($eligibleCount / ($buyQty + $getQty));
        $freeCount = $freeSets * $getQty;

        $discount = $unitPrice->multiply($freeCount);

        return new DiscountLine(
            promotionId: $promotion->id,
            promotionCode: $promotion->code,
            description: "Buy {$buyQty} Get {$getQty} Free",
            discountAmount: $discount
        );
    }
}
