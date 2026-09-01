<?php

declare(strict_types=1);

namespace Modules\Promotions\Services;

use Carbon\Carbon;
use Modules\Promotions\Contracts\ShippingPromotionBenefitResolverInterface;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Modules\Promotions\Models\PromotionCondition;
use Modules\Promotions\Registries\PromotionConditionRegistry;
use Modules\Shipping\Contracts\ShippingPromotionBenefitInterface;
use Modules\Shipping\ValueObjects\FreeShippingBenefitDTO;

class ShippingPromotionBenefitResolver implements ShippingPromotionBenefitResolverInterface
{
    public function __construct(
        private readonly PromotionConditionRegistry $conditionRegistry,
        private readonly CouponValidationService $couponValidationService,
    ) {}

    /**
     * @return array<int, ShippingPromotionBenefitInterface>
     */
    public function resolveBenefits(PromotionContext $context): array
    {
        $now = $context->effectiveAt ? Carbon::instance($context->effectiveAt) : Carbon::now();

        $promotions = Promotion::query()
            ->where('tenant_id', $context->tenantId)
            ->where('status', 'active')
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->whereHas('actions', fn ($q) => $q->where('action_type', 'free_shipping'))
            ->with(['conditions', 'actions', 'coupons'])
            ->orderByDesc('priority')
            ->get();

        $benefits = [];

        foreach ($promotions as $promotion) {
            // 1. Verify usage limit
            if ($promotion->usage_limit !== null && $promotion->times_used >= $promotion->usage_limit) {
                continue;
            }

            // 2. Authoritative Coupon Verification (if promotion has associated coupons)
            if ($promotion->coupons->isNotEmpty()) {
                $hasMatchingValidCoupon = false;
                foreach ($context->couponCodes as $code) {
                    $validCoupon = $this->couponValidationService->validate($code, $context);
                    if ($validCoupon !== null && (int) $validCoupon->promotion_id === (int) $promotion->id) {
                        $hasMatchingValidCoupon = true;
                        break;
                    }
                }
                if (! $hasMatchingValidCoupon) {
                    continue;
                }
            }

            // 3. Evaluate all Conditions
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

            // 4. Produce typed FreeShippingBenefitDTO
            foreach ($promotion->actions as $act) {
                /** @var PromotionAction $act */
                if ($act->action_type === 'free_shipping') {
                    $applicableCode = isset($act->parameters['applicable_method_code']) ? (string) $act->parameters['applicable_method_code'] : null;
                    $benefits[] = new FreeShippingBenefitDTO(
                        applicableMethodCode: $applicableCode,
                        description: $promotion->name ?? 'Free shipping promotion applied'
                    );
                }
            }

            // 5. Exclusivity & stop further rules
            if ($promotion->is_exclusive || $promotion->stop_further_rules) {
                break;
            }
        }

        return $benefits;
    }
}
