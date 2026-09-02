<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionItemFilterConditionInterface;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;

class CategoryCondition implements PromotionItemFilterConditionInterface
{
    public function getType(): string
    {
        return 'category';
    }

    public function isItemEligible(PromotionCartItem $item, array $parameters): bool
    {
        $categoryIds = $parameters['category_ids'] ?? [];

        return ! empty(array_intersect($item->categoryIds, $categoryIds));
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
