<?php

declare(strict_types=1);

namespace Modules\Promotions\Actions;

use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\PromotionActionInterface;
use Modules\Promotions\Contracts\PromotionItemFilterConditionInterface;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionCondition;
use Modules\Promotions\Registries\PromotionConditionRegistry;

class FixedDiscountAction implements PromotionActionInterface
{
    public function __construct(
        private readonly ?CurrencyConversionInterface $conversionService = null,
        private readonly ?PromotionConditionRegistry $conditionRegistry = null,
    ) {}

    public function getType(): string
    {
        return 'fixed_discount';
    }

    public function apply(Promotion $promotion, PromotionContext $context, array $parameters, MoneyValue $currentTotal): ?DiscountLine
    {
        $discountMinor = (int) ($parameters['amount_minor'] ?? 0);
        $discountCurrency = strtoupper((string) ($parameters['currency'] ?? $context->currency));

        $discount = MoneyValue::fromMinor($discountMinor, $discountCurrency);

        // Multi-currency safety: convert if discount currency differs from cart currency
        if ($discountCurrency !== $context->currency && $this->conversionService !== null) {
            $discount = $this->conversionService->convert($discount, $context->currency, $context->tenantId);
        }

        $capTotal = $currentTotal;

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

                if ($eligibleTotal->isLessThan($capTotal)) {
                    $capTotal = $eligibleTotal;
                }
            }
        }

        if ($discount->isGreaterThan($capTotal)) {
            $discount = $capTotal;
        }

        if ($discount->isZero()) {
            return null;
        }

        return new DiscountLine(
            promotionId: $promotion->id,
            promotionCode: $promotion->code,
            description: "Fixed discount of {$discount->format()}",
            discountAmount: $discount
        );
    }
}
