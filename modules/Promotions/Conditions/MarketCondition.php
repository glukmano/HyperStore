<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class MarketCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'market';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $marketIds = $parameters['market_ids'] ?? [];

        return $context->marketId !== null && in_array($context->marketId, $marketIds, true);
    }
}
