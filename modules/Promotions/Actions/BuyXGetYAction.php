<?php

declare(strict_types=1);

namespace Modules\Promotions\Actions;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\PromotionActionInterface;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;

class BuyXGetYAction implements PromotionActionInterface
{
    public function getType(): string
    {
        return 'buy_x_get_y';
    }

    public function apply(Promotion $promotion, PromotionContext $context, array $parameters, MoneyValue $currentTotal): ?DiscountLine
    {
        $buyQty = (int) ($parameters['buy_quantity'] ?? 2);
        $getQty = (int) ($parameters['get_free_quantity'] ?? 1);
        $targetProductId = isset($parameters['product_id']) ? (int) $parameters['product_id'] : null;

        $eligibleCount = 0;
        $unitPrice = null;

        foreach ($context->items as $item) {
            if ($targetProductId === null || $item->productId === $targetProductId) {
                $eligibleCount += $item->quantity;
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
