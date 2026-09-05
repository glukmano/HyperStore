<?php

declare(strict_types=1);

namespace Modules\Affiliate\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Affiliate\Contracts\AffiliateAttributionServiceInterface;
use Modules\Affiliate\Contracts\AffiliateCommissionRuleResolverInterface;
use Modules\Affiliate\Contracts\AffiliateFraudDetectionServiceInterface;
use Modules\Affiliate\Contracts\AffiliateTargetResolverInterface;
use Modules\Affiliate\Enums\AffiliateAttributionStrategy;
use Modules\Affiliate\Enums\AffiliateConversionStatus;
use Modules\Affiliate\Enums\AffiliateTargetType;
use Modules\Affiliate\Models\Affiliate;
use Modules\Affiliate\Models\AffiliateAttribution;
use Modules\Affiliate\Models\AffiliateCampaign;
use Modules\Affiliate\Models\AffiliateClick;
use Modules\Affiliate\Models\AffiliateConversion;
use Modules\Affiliate\Models\AffiliateConversionItem;
use Modules\Affiliate\Models\AffiliateReferralCode;
use Modules\Catalog\Models\Product;
use Modules\Order\Models\Order;
use Modules\Promotions\Models\Coupon;

/**
 * Owner Delta correction §2: freezes the resolved Affiliate attribution at
 * the Order-creation boundary — reads the visitor's (already-hashed) first-
 * party attribution token and/or the order's applied coupon code, resolves
 * exactly once, and writes an immutable snapshot. Payment=paid never calls
 * back into this class to recompute anything; it only activates what is
 * already frozen here (see ActivateAffiliateConversionOnOrderPaidListener).
 */
final class AffiliateAttributionService implements AffiliateAttributionServiceInterface
{
    public function __construct(
        private readonly AffiliateTargetResolverInterface $targetResolver,
        private readonly AffiliateCommissionRuleResolverInterface $ruleResolver,
        private readonly AffiliateFraudDetectionServiceInterface $fraudDetection,
    ) {}

    public function freezeAttributionForOrder(Order $order, ?string $visitorTokenHash, ?string $couponCode): ?AffiliateAttribution
    {
        $existing = AffiliateAttribution::where('tenant_id', $order->tenant_id)
            ->where('order_id', $order->id)
            ->whereNull('superseded_by_attribution_id')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $resolution = $this->resolveViaCoupon((int) $order->tenant_id, $couponCode)
            ?? $this->resolveViaClick((int) $order->tenant_id, $visitorTokenHash);

        if ($resolution === null) {
            return null;
        }

        return DB::transaction(function () use ($order, $resolution): AffiliateAttribution {
            $attribution = $this->createAttributionAndConversion(
                order: $order,
                affiliate: $resolution['affiliate'],
                referralCode: $resolution['referral_code'],
                campaign: $resolution['campaign'],
                strategy: $resolution['strategy'],
                windowDays: $resolution['window_days'],
                clickId: $resolution['click_id'],
                visitorTokenHash: $resolution['visitor_token_hash'],
                isManual: false,
                createdByUserId: null,
            );

            $this->fraudDetection->evaluateAttribution($attribution);

            return $attribution;
        });
    }

    public function manuallyReattribute(Order $order, int $newAffiliateId, int $actingUserId, ?int $newAffiliateCampaignId = null): AffiliateAttribution
    {
        return DB::transaction(function () use ($order, $newAffiliateId, $actingUserId, $newAffiliateCampaignId): AffiliateAttribution {
            /** @var Affiliate $affiliate */
            $affiliate = Affiliate::where('tenant_id', $order->tenant_id)->where('id', $newAffiliateId)->lockForUpdate()->firstOrFail();

            $campaign = $newAffiliateCampaignId !== null
                ? AffiliateCampaign::where('tenant_id', $order->tenant_id)->where('id', $newAffiliateCampaignId)->first()
                : null;

            $previous = AffiliateAttribution::where('tenant_id', $order->tenant_id)
                ->where('order_id', $order->id)
                ->whereNull('superseded_by_attribution_id')
                ->lockForUpdate()
                ->first();

            $targetType = $campaign !== null ? $campaign->target_type : AffiliateTargetType::Platform;
            $targetId = $campaign !== null ? $campaign->target_id : null;

            $new = $this->createAttributionAndConversion(
                order: $order,
                affiliate: $affiliate,
                referralCode: null,
                campaign: $campaign,
                strategy: AffiliateAttributionStrategy::Manual,
                windowDays: null,
                clickId: null,
                visitorTokenHash: null,
                isManual: true,
                createdByUserId: $actingUserId,
            );

            if ($previous !== null) {
                $previous->superseded_by_attribution_id = $new->id;
                $previous->superseded_at = CarbonImmutable::now();
                $previous->save();

                // Owner Delta correction §6: if the previous attribution had
                // already accrued (its AffiliateConversion is 'accrued'),
                // reverse it via compensating entries — never mutate history.
                $previousConversion = AffiliateConversion::where('tenant_id', $order->tenant_id)
                    ->where('affiliate_attribution_id', $previous->id)
                    ->first();

                if ($previousConversion !== null && $previousConversion->status === AffiliateConversionStatus::Accrued) {
                    app(AffiliateConversionReversalService::class)->reverseConversion($previousConversion, 'manual_reattribution');
                }
            }

            return $new;
        });
    }

    /**
     * @return array{affiliate: Affiliate, referral_code: AffiliateReferralCode|null, campaign: AffiliateCampaign|null, strategy: AffiliateAttributionStrategy, window_days: int|null, click_id: int|null, visitor_token_hash: string|null}|null
     */
    private function resolveViaCoupon(int $tenantId, ?string $couponCode): ?array
    {
        if ($couponCode === null || trim($couponCode) === '') {
            return null;
        }

        /** @var Coupon|null $coupon */
        $coupon = Coupon::where('tenant_id', $tenantId)->where('code', $couponCode)->first();
        if ($coupon === null || $coupon->affiliate_id === null) {
            return null;
        }

        /** @var Affiliate|null $affiliate */
        $affiliate = Affiliate::where('tenant_id', $tenantId)->where('id', $coupon->affiliate_id)->first();
        if ($affiliate === null) {
            return null;
        }

        return [
            'affiliate' => $affiliate,
            'referral_code' => null,
            'campaign' => null,
            'strategy' => AffiliateAttributionStrategy::Coupon,
            'window_days' => null,
            'click_id' => null,
            'visitor_token_hash' => null,
        ];
    }

    /**
     * @return array{affiliate: Affiliate, referral_code: AffiliateReferralCode|null, campaign: AffiliateCampaign|null, strategy: AffiliateAttributionStrategy, window_days: int|null, click_id: int|null, visitor_token_hash: string|null}|null
     */
    private function resolveViaClick(int $tenantId, ?string $visitorTokenHash): ?array
    {
        if ($visitorTokenHash === null) {
            return null;
        }

        $clicks = AffiliateClick::where('tenant_id', $tenantId)
            ->where('visitor_token_hash', $visitorTokenHash)
            ->with('referralCode.campaign')
            ->orderBy('clicked_at', 'asc')
            ->get();

        if ($clicks->isEmpty()) {
            return null;
        }

        // Determine strategy from the most recent click's campaign (or the
        // platform default when no campaign is attached) — Owner Delta
        // correction §8: one authoritative configuration location.
        $lastClick = $clicks->last();
        $campaign = $lastClick->referralCode->campaign;
        $strategy = $campaign !== null ? $campaign->attribution_strategy : AffiliateAttributionStrategy::LastClick;
        $windowDays = $campaign !== null ? $campaign->attribution_window_days : 30;

        $cutoff = CarbonImmutable::now()->subDays($windowDays);
        $eligibleClicks = $clicks->filter(fn (AffiliateClick $c) => $c->clicked_at->gte($cutoff));

        if ($eligibleClicks->isEmpty()) {
            return null;
        }

        $chosenClick = $strategy === AffiliateAttributionStrategy::FirstClick
            ? $eligibleClicks->first()
            : $eligibleClicks->last();

        $referralCode = $chosenClick->referralCode;
        $affiliate = $referralCode->affiliate;
        $chosenCampaign = $referralCode->campaign;

        return [
            'affiliate' => $affiliate,
            'referral_code' => $referralCode,
            'campaign' => $chosenCampaign,
            'strategy' => $strategy,
            'window_days' => $windowDays,
            'click_id' => $chosenClick->id,
            'visitor_token_hash' => $visitorTokenHash,
        ];
    }

    private function createAttributionAndConversion(
        Order $order,
        Affiliate $affiliate,
        ?AffiliateReferralCode $referralCode,
        ?AffiliateCampaign $campaign,
        AffiliateAttributionStrategy $strategy,
        ?int $windowDays,
        ?int $clickId,
        ?string $visitorTokenHash,
        bool $isManual,
        ?int $createdByUserId,
    ): AffiliateAttribution {
        $targetType = match (true) {
            $campaign !== null => $campaign->target_type,
            $referralCode !== null => $referralCode->target_type,
            default => AffiliateTargetType::Platform,
        };
        $targetId = match (true) {
            $campaign !== null => $campaign->target_id,
            $referralCode !== null => $referralCode->target_id,
            default => null,
        };

        $this->targetResolver->assertEligible((int) $order->tenant_id, $targetType, $targetId);

        /** @var AffiliateAttribution $attribution */
        $attribution = AffiliateAttribution::create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'affiliate_id' => $affiliate->id,
            'affiliate_referral_code_id' => $referralCode?->id,
            'affiliate_campaign_id' => $campaign?->id,
            'attribution_strategy' => $strategy,
            'attribution_window_days_used' => $windowDays,
            'attributed_click_id' => $clickId,
            'visitor_token_hash' => $visitorTokenHash,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'attributed_at' => CarbonImmutable::now(),
            'is_manual' => $isManual,
            'created_by_user_id' => $createdByUserId,
        ]);

        /** @var AffiliateConversion $conversion */
        $conversion = AffiliateConversion::create([
            'tenant_id' => $order->tenant_id,
            'affiliate_attribution_id' => $attribution->id,
            'affiliate_id' => $affiliate->id,
            'order_id' => $order->id,
            'currency' => $order->currency,
            'status' => AffiliateConversionStatus::Pending,
            'converted_at' => CarbonImmutable::now(),
        ]);

        foreach ($order->items as $item) {
            if (! $this->targetResolver->orderItemMatchesTarget((int) $order->tenant_id, $targetType, $targetId, (int) $item->id)) {
                continue;
            }

            $base = max(0, (int) $item->subtotal_minor - (int) $item->discount_minor);
            if ($base <= 0) {
                continue;
            }

            $categoryId = null;
            if ($item->product_id !== null) {
                $product = Product::find($item->product_id);
                $categoryId = $product?->categories()->first()?->id;
            }

            $rule = $this->ruleResolver->resolve(
                (int) $order->tenant_id,
                (int) $affiliate->id,
                $campaign?->id,
                $categoryId !== null ? (int) $categoryId : null,
                (string) $order->currency
            );

            if ($rule === null) {
                // No matching rule in THIS currency: never silently convert
                // or borrow a rule from another currency (Owner Delta §14).
                continue;
            }

            $variableMinor = intdiv(($base * $rule->rate_basis_points) + 5000, 10000);
            $commissionMinor = min($base, $variableMinor + $rule->fixed_fee_minor);

            AffiliateConversionItem::create([
                'tenant_id' => $order->tenant_id,
                'affiliate_conversion_id' => $conversion->id,
                'order_item_id' => $item->id,
                'currency' => $order->currency,
                'commissionable_base_minor' => $base,
                'commission_rate_bps' => $rule->rate_basis_points,
                'commission_fixed_fee_minor' => $rule->fixed_fee_minor,
                'commission_amount_minor' => $commissionMinor,
                'commission_rule_ref' => $rule->uuid,
            ]);
        }

        return $attribution;
    }
}
