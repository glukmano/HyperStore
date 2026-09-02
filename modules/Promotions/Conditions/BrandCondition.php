<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionItemFilterConditionInterface;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;

class BrandCondition implements PromotionItemFilterConditionInterface
{
    public function getType(): string
    {
        return 'brand';
    }

    public function isItemEligible(PromotionCartItem $item, array $parameters): bool
    {
        $brandIds = $parameters['brand_ids'] ?? [];

        return $item->brandId !== null && in_array($item->brandId, $brandIds, true);
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
