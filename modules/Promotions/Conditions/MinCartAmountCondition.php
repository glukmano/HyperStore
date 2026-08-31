<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class MinCartAmountCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'min_cart_amount';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $requiredMinor = (int) ($parameters['min_amount_minor'] ?? 0);
        $totalMinor = 0;
        foreach ($context->items as $item) {
            $totalMinor += $item->getTotal()->getMinorAmount();
        }

        return $totalMinor >= $requiredMinor;
    }
}
