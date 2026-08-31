<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class StoreCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'store';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $storeIds = $parameters['store_ids'] ?? [];

        return $context->storeId !== null && in_array($context->storeId, $storeIds, true);
    }
}
