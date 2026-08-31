<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class CustomerGroupCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'customer_group';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $groupIds = $parameters['customer_group_ids'] ?? [];

        return $context->customerGroupId !== null && in_array($context->customerGroupId, $groupIds, true);
    }
}
