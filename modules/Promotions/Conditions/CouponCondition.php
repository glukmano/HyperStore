<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class CouponCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'coupon';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $requiredCode = strtoupper((string) ($parameters['code'] ?? ''));
        foreach ($context->couponCodes as $code) {
            if (strtoupper($code) === $requiredCode) {
                return true;
            }
        }

        return false;
    }
}
