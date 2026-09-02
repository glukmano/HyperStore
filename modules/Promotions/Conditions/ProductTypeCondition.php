<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionItemFilterConditionInterface;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;

class ProductTypeCondition implements PromotionItemFilterConditionInterface
{
    public function getType(): string
    {
        return 'product_type';
    }

    public function isItemEligible(PromotionCartItem $item, array $parameters): bool
    {
        $allowedTypes = $parameters['product_types'] ?? [];

        return $item->productType !== null && in_array($item->productType, $allowedTypes, true);
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
