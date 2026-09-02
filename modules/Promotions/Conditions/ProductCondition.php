<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionItemFilterConditionInterface;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;

class ProductCondition implements PromotionItemFilterConditionInterface
{
    public function getType(): string
    {
        return 'product';
    }

    public function isItemEligible(PromotionCartItem $item, array $parameters): bool
    {
        $allowedProductIds = $parameters['product_ids'] ?? [];

        return in_array($item->productId, $allowedProductIds, true);
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        foreach ($context->items as $item) {
            if ($this->isItemEligible($item, $parameters)) {
                return true;
            }
        }

        return false;
    }
}
