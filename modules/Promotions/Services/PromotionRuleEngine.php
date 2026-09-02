<?php

declare(strict_types=1);

namespace Modules\Promotions\Services;

use Carbon\Carbon;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\PromotionLineEligibilityResolverInterface;
use Modules\Promotions\DTOs\PromotionBenefitDTO;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\DTOs\PromotionResult;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Modules\Promotions\Models\PromotionCondition;
use Modules\Promotions\Registries\PromotionActionRegistry;
use Modules\Promotions\Registries\PromotionConditionRegistry;

class PromotionRuleEngine
{
    public function __construct(
        private readonly PromotionConditionRegistry $conditionRegistry,
        private readonly PromotionActionRegistry $actionRegistry,
        private readonly CouponValidationService $couponValidationService,
        private readonly PromotionLineEligibilityResolverInterface $eligibilityResolver,
    ) {}

    public function evaluate(PromotionContext $context): PromotionResult
    {
        $now = $context->effectiveAt ? Carbon::instance($context->effectiveAt) : Carbon::now();

        // 1. Calculate subtotal
        $subtotal = MoneyValue::zero($context->currency);
        foreach ($context->items as $item) {
            $subtotal = $subtotal->add($item->getTotal());
        }

        $currentTotal = $subtotal;
        $discounts = [];
        $benefits = [];
        $appliedPromotionIds = [];
        $entitlements = [];

        // 2. Fetch active promotions in priority order
        $promotions = Promotion::query()
            ->where('tenant_id', $context->tenantId)
            ->where('status', 'active')
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->with(['conditions', 'actions', 'coupons'])
            ->orderByDesc('priority')
            ->get();

        foreach ($promotions as $promotion) {
            if ($currentTotal->isZero()) {
                break;
            }

            // Verify usage limit
            if ($promotion->usage_limit !== null && $promotion->times_used >= $promotion->usage_limit) {
                continue;
            }

            // If promotion has associated coupons, at least one supplied coupon must be valid for this promotion
            $matchedCouponCode = null;
            if ($promotion->coupons->isNotEmpty()) {
                $hasMatchingValidCoupon = false;
                foreach ($context->couponCodes as $code) {
                    $validCoupon = $this->couponValidationService->validate($code, $context);
                    if ($validCoupon !== null && (int) $validCoupon->promotion_id === (int) $promotion->id) {
                        $hasMatchingValidCoupon = true;
                        $matchedCouponCode = $validCoupon->code;
                        break;
                    }
                }
                if (! $hasMatchingValidCoupon) {
                    continue;
                }
            }

            // Evaluate all conditions
            $allConditionsPass = true;
            foreach ($promotion->conditions as $cond) {
                /** @var PromotionCondition $cond */
                $handler = $this->conditionRegistry->get($cond->condition_type);
                if ($handler === null || ! $handler->evaluate($context, $cond->parameters ?? [])) {
                    $allConditionsPass = false;
                    break;
                }
            }

            if (! $allConditionsPass) {
                continue;
            }

            $appliedPromotionIds[] = $promotion->id;

            // Apply actions & collect typed benefits
            foreach ($promotion->actions as $act) {
                /** @var PromotionAction $act */
                $handler = $this->actionRegistry->get($act->action_type);
                if ($handler === null) {
                    continue;
                }

                $discountLine = $handler->apply($promotion, $context, $act->parameters ?? [], $currentTotal);
                if ($discountLine !== null) {
                    $eligibleCartLineIds = $this->eligibilityResolver->resolve($promotion, $context, $act);
                    $discountLine = $discountLine->withEligibleCartLineIds($eligibleCartLineIds);

                    $discounts[] = $discountLine;
                    $currentTotal = $currentTotal->subtract($discountLine->discountAmount);
                }

                if ($act->action_type === 'free_shipping') {
                    $benefits[] = new PromotionBenefitDTO(
                        promotionId: $promotion->id,
                        type: 'free_shipping',
                        parameters: $act->parameters ?? [],
                        description: $promotion->name ?? 'Free shipping promotion applied',
                        couponCode: $matchedCouponCode
                    );
                }
            }

            // Check exclusivity & stop_further_rules
            if ($promotion->is_exclusive || $promotion->stop_further_rules) {
                break;
            }
        }

        $totalDiscount = $subtotal->subtract($currentTotal);

        return new PromotionResult(
            subtotal: $subtotal,
            totalDiscount: $totalDiscount,
            finalTotal: $currentTotal,
            discounts: $discounts,
            entitlements: $entitlements,
            benefits: $benefits,
            appliedPromotionIds: $appliedPromotionIds
        );
    }
}
