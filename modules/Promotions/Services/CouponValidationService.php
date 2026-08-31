<?php

declare(strict_types=1);

namespace Modules\Promotions\Services;

use Carbon\Carbon;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Coupon;
use Modules\Promotions\Models\CouponUsage;

class CouponValidationService
{
    public function validate(string $code, PromotionContext $context): ?Coupon
    {
        $normalizedCode = strtoupper(trim($code));

        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()
            ->where('tenant_id', $context->tenantId)
            ->where('code', $normalizedCode)
            ->where('status', 'active')
            ->first();

        if ($coupon === null) {
            return null;
        }

        $now = $context->effectiveAt ? Carbon::instance($context->effectiveAt) : Carbon::now();

        // 1. Validity dates
        if ($coupon->valid_from !== null && $coupon->valid_from->isAfter($now)) {
            return null;
        }
        if ($coupon->valid_until !== null && $coupon->valid_until->isBefore($now)) {
            return null;
        }

        // 2. Global usage limit
        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            return null;
        }

        // 3. Per-customer usage limit
        if ($coupon->per_customer_limit !== null && $context->customerId !== null) {
            $userUsageCount = CouponUsage::query()
                ->where('coupon_id', $coupon->id)
                ->where('customer_id', $context->customerId)
                ->count();

            if ($userUsageCount >= $coupon->per_customer_limit) {
                return null;
            }
        }

        return $coupon;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordUsage(Coupon $coupon, ?int $customerId = null, ?string $customerEmail = null, array $metadata = []): CouponUsage
    {
        $coupon->increment('times_used');

        return CouponUsage::create([
            'tenant_id' => $coupon->tenant_id,
            'coupon_id' => $coupon->id,
            'customer_id' => $customerId,
            'customer_email' => $customerEmail,
            'used_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}
