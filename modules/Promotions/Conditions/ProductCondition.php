<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class ProductCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'product';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $allowedProductIds = $parameters['product_ids'] ?? [];
        foreach ($context->items as $item) {
            if (in_array($item->productId, $allowedProductIds, true)) {
                return true;
            }
        }

        return false;
    }
}
