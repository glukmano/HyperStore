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
        /** @var numeric-string $minQty */
        $minQty = (string) ($parameters['min_quantity'] ?? '1');
        /** @var numeric-string $totalQty */
        $totalQty = '0.0000';
        foreach ($context->items as $item) {
            /** @var numeric-string $itemQty */
            $itemQty = (string) $item->quantity;
            /** @var numeric-string $totalQty */
            $totalQty = bcadd($totalQty, $itemQty, 4);
        }

        return bccomp($totalQty, $minQty, 4) >= 0;
    }
}
