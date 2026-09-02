<?php

declare(strict_types=1);

namespace Modules\Promotions\Actions;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\PromotionActionInterface;
use Modules\Promotions\Contracts\PromotionItemFilterConditionInterface;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionCondition;
use Modules\Promotions\Registries\PromotionConditionRegistry;

class PercentageDiscountAction implements PromotionActionInterface
{
    public function __construct(
        private readonly ?PromotionConditionRegistry $conditionRegistry = null,
    ) {}

    public function getType(): string
    {
        return 'percentage_discount';
    }

    public function apply(Promotion $promotion, PromotionContext $context, array $parameters, MoneyValue $currentTotal): ?DiscountLine
    {
        $percent = $parameters['percentage'] ?? 0;
        if ($percent <= 0 || $currentTotal->isZero()) {
            return null;
        }

        $baseTotal = $currentTotal;

        if ($this->conditionRegistry !== null) {
            $hasItemFilter = false;
            foreach ($promotion->conditions as $cond) {
                /** @var PromotionCondition $cond */
                $handler = $this->conditionRegistry->get($cond->condition_type);
                if ($handler instanceof PromotionItemFilterConditionInterface) {
                    $hasItemFilter = true;
                    break;
                }
            }

            if ($hasItemFilter) {
                $eligibleTotal = MoneyValue::zero($context->currency);
                foreach ($context->items as $item) {
                    $itemMatches = true;
                    foreach ($promotion->conditions as $cond) {
                        /** @var PromotionCondition $cond */
                        $handler = $this->conditionRegistry->get($cond->condition_type);
                        if ($handler instanceof PromotionItemFilterConditionInterface) {
                            if (! $handler->isItemEligible($item, $cond->parameters ?? [])) {
                                $itemMatches = false;
                                break;
                            }
                        }
                    }

                    if ($itemMatches) {
                        $eligibleTotal = $eligibleTotal->add($item->getTotal());
                    }
                }

                if ($eligibleTotal->isGreaterThan($currentTotal)) {
                    $eligibleTotal = $currentTotal;
                }

                $baseTotal = $eligibleTotal;
            }
        }

        if ($baseTotal->isZero()) {
            return null;
        }

        $discount = $baseTotal->percentage($percent);

        return new DiscountLine(
            promotionId: $promotion->id,
            promotionCode: $promotion->code,
            description: "{$percent}% off cart total",
            discountAmount: $discount
        );
    }
}
