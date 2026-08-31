<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class CategoryCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'category';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $categoryIds = $parameters['category_ids'] ?? [];
        foreach ($context->items as $item) {
            if (! empty(array_intersect($item->categoryIds, $categoryIds))) {
                return true;
            }
        }

        return false;
    }
}
