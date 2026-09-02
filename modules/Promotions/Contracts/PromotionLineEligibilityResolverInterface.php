<?php

declare(strict_types=1);

namespace Modules\Promotions\Contracts;

use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;

interface PromotionLineEligibilityResolverInterface
{
    /**
     * Resolve the authoritative list of eligible cartLineIds for a given promotion and action.
     *
     * @return list<int>
     */
    public function resolve(Promotion $promotion, PromotionContext $context, PromotionAction $action): array;

    /**
     * Retrieve the list of eligible PromotionCartItem instances for a given promotion and action.
     *
     * @return list<PromotionCartItem>
     */
    public function getEligibleItems(Promotion $promotion, PromotionContext $context, PromotionAction $action): array;

    /**
     * Determine if a promotion specifies any item-level filter conditions.
     */
    public function hasItemFilter(Promotion $promotion): bool;
}
