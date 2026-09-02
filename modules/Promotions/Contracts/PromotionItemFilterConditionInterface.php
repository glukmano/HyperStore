<?php

declare(strict_types=1);

namespace Modules\Promotions\Contracts;

use Modules\Promotions\DTOs\PromotionCartItem;

interface PromotionItemFilterConditionInterface extends PromotionConditionInterface
{
    /**
     * Determine if an individual cart item satisfies this condition.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function isItemEligible(PromotionCartItem $item, array $parameters): bool;
}
