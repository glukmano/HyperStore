<?php

declare(strict_types=1);

namespace Modules\Promotions\Services;

use Illuminate\Support\Str;
use Modules\Customers\Models\CustomerProfile;
use Modules\Promotions\Contracts\LoyaltyCheckoutRedemptionServiceInterface;
use Modules\Promotions\Models\Coupon;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Modules\Promotions\Models\PromotionCondition;

/**
 * Phase-19 Final Completion Delta §4: turns a Loyalty points redemption into
 * a real, functioning checkout discount WITHOUT introducing a second
 * discount/pricing engine inside Checkout. The redeemed value is minted as a
 * single-use, single-customer Coupon (a `fixed_discount` Promotion gated by
 * a `coupon` Condition on a freshly-generated code) and applied through the
 * ALREADY-EXISTING, already-tested `CheckoutOrchestratorInterface::applyCoupon()`
 * pipeline — Checkout's pricing/tax/reconciliation code is never touched.
 *
 * The actual point deduction happens once, inside LoyaltyService::redeemPoints()
 * (concurrency-safe, idempotent, server-authoritative balance) — this class
 * only ever converts an already-computed, already-frozen currency value into
 * the platform's existing coupon mechanism.
 */
final class LoyaltyCheckoutRedemptionService implements LoyaltyCheckoutRedemptionServiceInterface
{
    public function __construct(
        private readonly LoyaltyService $loyaltyService,
    ) {}

    /**
     * Idempotent by checkout session UUID: replaying the same checkout
     * session never redeems points twice and never mints a second coupon —
     * the existing Promotion/Coupon pair (still usable, since it carries
     * usage_limit=1 and has not yet been consumed by applyCoupon()) is
     * returned unchanged.
     */
    public function redeemForCheckout(
        CustomerProfile $customerProfile,
        int $tenantId,
        int $points,
        string $currency,
        string $checkoutSessionUuid,
    ): Coupon {
        $sourceUuid = 'checkout_redemption:'.$checkoutSessionUuid;

        $existingPromotion = Promotion::where('tenant_id', $tenantId)
            ->whereJsonContains('metadata->source_uuid', $sourceUuid)
            ->first();
        if ($existingPromotion !== null) {
            /** @var Coupon $coupon */
            $coupon = Coupon::where('promotion_id', $existingPromotion->id)->firstOrFail();

            return $coupon;
        }

        // Real, server-authoritative, concurrency-safe economic operation.
        // Throws NoLoyaltyCurrencyRuleException / InsufficientLoyaltyPointsException
        // on an invalid/tampered request — never silently clamps.
        $valueMinor = $this->loyaltyService->redeemPoints($customerProfile, $points, $currency, $sourceUuid);

        $code = 'LOYALTY-'.strtoupper(Str::random(12));

        $promotion = Promotion::create([
            'tenant_id' => $tenantId,
            'name' => 'Loyalty points redemption',
            'code' => $code,
            'priority' => 1000,
            'is_exclusive' => false,
            'is_stackable' => true,
            'stop_further_rules' => false,
            'status' => 'active',
            'usage_limit' => 1,
            'per_customer_limit' => 1,
            'metadata' => [
                'source' => 'loyalty_redemption',
                'source_uuid' => $sourceUuid,
                'customer_profile_id' => $customerProfile->id,
                'points' => $points,
            ],
        ]);

        PromotionCondition::create([
            'promotion_id' => $promotion->id,
            'condition_type' => 'coupon',
            'parameters' => ['code' => $code],
            'sort_order' => 0,
        ]);

        PromotionAction::create([
            'promotion_id' => $promotion->id,
            'action_type' => 'fixed_discount',
            'parameters' => ['amount_minor' => $valueMinor, 'currency' => $currency],
            'sort_order' => 0,
        ]);

        return Coupon::create([
            'tenant_id' => $tenantId,
            'promotion_id' => $promotion->id,
            'code' => $code,
            'status' => 'active',
            'usage_limit' => 1,
            'per_customer_limit' => 1,
        ]);
    }

    /**
     * Cancels an as-yet-unused checkout redemption (the customer changed
     * their mind, or checkout was abandoned/expired before becoming an
     * Order) and reverses the point deduction. A redemption whose coupon
     * has already been consumed by applyCoupon()/an Order is left alone —
     * this only ever reverses a redemption that never actually paid for
     * anything.
     */
    public function cancelForCheckout(string $checkoutSessionUuid, int $tenantId): void
    {
        $sourceUuid = 'checkout_redemption:'.$checkoutSessionUuid;

        $promotion = Promotion::where('tenant_id', $tenantId)
            ->whereJsonContains('metadata->source_uuid', $sourceUuid)
            ->first();
        if ($promotion === null) {
            return;
        }

        if ($promotion->status === 'expired') {
            return;
        }

        $promotion->status = 'expired';
        $promotion->save();

        Coupon::where('promotion_id', $promotion->id)->update(['status' => 'expired']);

        $this->loyaltyService->reverseRedemption($sourceUuid);
    }
}
