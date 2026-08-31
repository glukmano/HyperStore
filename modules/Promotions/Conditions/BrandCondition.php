<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class BrandCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'brand';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $brandIds = $parameters['brand_ids'] ?? [];
        foreach ($context->items as $item) {
            if ($item->brandId !== null && in_array($item->brandId, $brandIds, true)) {
                return true;
            }
        }

        return false;
    }
}
