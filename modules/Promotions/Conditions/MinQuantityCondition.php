<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class MinQuantityCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'min_quantity';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $minQty = (int) ($parameters['min_quantity'] ?? 1);
        $totalQty = 0;
        foreach ($context->items as $item) {
            $totalQty += $item->quantity;
        }

        return $totalQty >= $minQty;
    }
}
