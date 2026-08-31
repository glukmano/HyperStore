<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class FirstOrderCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'first_order';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        // Contextual hook: evaluates true if customer has no prior confirmed orders/usages
        // In future phases, verified against Order/Customer transaction history
        return $context->customerId === null || ($parameters['is_first_order'] ?? true) === true;
    }
}
