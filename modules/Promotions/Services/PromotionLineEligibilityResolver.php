<?php

declare(strict_types=1);

namespace Modules\Promotions\Services;

use Modules\Promotions\Contracts\PromotionItemFilterActionInterface;
use Modules\Promotions\Contracts\PromotionItemFilterConditionInterface;
use Modules\Promotions\Contracts\PromotionLineEligibilityResolverInterface;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Modules\Promotions\Models\PromotionCondition;
use Modules\Promotions\Registries\PromotionActionRegistry;
use Modules\Promotions\Registries\PromotionConditionRegistry;

class PromotionLineEligibilityResolver implements PromotionLineEligibilityResolverInterface
{
    public function __construct(
        private readonly PromotionConditionRegistry $conditionRegistry,
        private readonly PromotionActionRegistry $actionRegistry,
    ) {}

    /**
     * @return list<PromotionCartItem>
     */
    public function getEligibleItems(Promotion $promotion, PromotionContext $context, PromotionAction $action): array
    {
        $actionHandler = $this->actionRegistry->get($action->action_type);
        $eligibleItems = [];

        foreach ($context->items as $item) {
            $isEligible = true;

            // 1. Evaluate all registered item-filter conditions on this promotion
            foreach ($promotion->conditions as $cond) {
                /** @var PromotionCondition $cond */
                $condHandler = $this->conditionRegistry->get($cond->condition_type);
                if ($condHandler instanceof PromotionItemFilterConditionInterface) {
                    if (! $condHandler->isItemEligible($item, $cond->parameters ?? [])) {
                        $isEligible = false;
                        break;
                    }
                }
            }

            if (! $isEligible) {
                continue;
            }

            // 2. Evaluate if action specifies target item filtering
            if ($actionHandler instanceof PromotionItemFilterActionInterface) {
                if (! $actionHandler->isItemTargeted($item, $action->parameters ?? [])) {
                    continue;
                }
            }

            $eligibleItems[] = $item;
        }

        return $eligibleItems;
    }

    /**
     * @return list<int>
     */
    public function resolve(Promotion $promotion, PromotionContext $context, PromotionAction $action): array
    {
        $eligibleItems = $this->getEligibleItems($promotion, $context, $action);
        $lineIds = [];

        foreach ($eligibleItems as $item) {
            if ($item->cartLineId !== null && $item->cartLineId > 0) {
                $lineIds[] = $item->cartLineId;
            }
        }

        return array_values(array_unique($lineIds, SORT_NUMERIC));
    }

    public function hasItemFilter(Promotion $promotion): bool
    {
        foreach ($promotion->conditions as $cond) {
            /** @var PromotionCondition $cond */
            $handler = $this->conditionRegistry->get($cond->condition_type);
            if ($handler instanceof PromotionItemFilterConditionInterface) {
                return true;
            }
        }

        return false;
    }
}
