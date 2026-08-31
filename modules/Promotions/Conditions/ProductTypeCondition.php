<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class ProductTypeCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'product_type';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $allowedTypes = $parameters['product_types'] ?? [];
        foreach ($context->items as $item) {
            if ($item->productType !== null && in_array($item->productType, $allowedTypes, true)) {
                return true;
            }
        }

        return false;
    }
}
