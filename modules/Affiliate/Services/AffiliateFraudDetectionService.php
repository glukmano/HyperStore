<?php

declare(strict_types=1);

namespace Modules\Affiliate\Services;

use Carbon\CarbonImmutable;
use Modules\Affiliate\Contracts\AffiliateFraudDetectionServiceInterface;
use Modules\Affiliate\Enums\AffiliateFraudFlagType;
use Modules\Affiliate\Models\AffiliateAttribution;
use Modules\Affiliate\Models\AffiliateClick;
use Modules\Affiliate\Models\AffiliateFraudFlag;
use Modules\Order\Models\Order;

/**
 * Rule-based only, deliberately not ML/AI (Owner Delta explicit exclusion).
 * Every flag is non-blocking: the conversion always proceeds; a human
 * reviews flags in Control Center via AffiliatePayableSubledgerService's
 * existing hold/release pair.
 */
final class AffiliateFraudDetectionService implements AffiliateFraudDetectionServiceInterface
{
    private const CLICK_VELOCITY_WINDOW_MINUTES = 10;

    private const CLICK_VELOCITY_THRESHOLD = 20;

    private const DUPLICATE_FINGERPRINT_THRESHOLD = 5;

    public function evaluateAttribution(AffiliateAttribution $attribution): void
    {
        $this->checkSelfReferral($attribution);
        $this->checkClickVelocity($attribution);
        $this->checkDuplicateFingerprint($attribution);
    }

    private function checkSelfReferral(AffiliateAttribution $attribution): void
    {
        $affiliate = $attribution->affiliate;
        if ($affiliate->user_id === null) {
            return;
        }

        /** @var Order|null $order */
        $order = $attribution->order;
        if ($order === null || $order->user_id === null) {
            return;
        }

        if ((int) $order->user_id === (int) $affiliate->user_id) {
            $this->flag($attribution, AffiliateFraudFlagType::SelfReferral, [
                'order_id' => $order->id,
                'affiliate_user_id' => $affiliate->user_id,
            ]);
        }
    }

    private function checkClickVelocity(AffiliateAttribution $attribution): void
    {
        if ($attribution->visitor_token_hash === null) {
            return;
        }

        $windowStart = CarbonImmutable::now()->subMinutes(self::CLICK_VELOCITY_WINDOW_MINUTES);

        $count = AffiliateClick::where('tenant_id', $attribution->tenant_id)
            ->where('visitor_token_hash', $attribution->visitor_token_hash)
            ->where('clicked_at', '>=', $windowStart)
            ->count();

        if ($count >= self::CLICK_VELOCITY_THRESHOLD) {
            $this->flag($attribution, AffiliateFraudFlagType::ClickVelocityAnomaly, [
                'visitor_token_hash' => $attribution->visitor_token_hash,
                'click_count' => $count,
                'window_minutes' => self::CLICK_VELOCITY_WINDOW_MINUTES,
            ]);
        }
    }

    private function checkDuplicateFingerprint(AffiliateAttribution $attribution): void
    {
        if ($attribution->visitor_token_hash === null) {
            return;
        }

        $conversionCount = AffiliateAttribution::where('tenant_id', $attribution->tenant_id)
            ->where('affiliate_id', $attribution->affiliate_id)
            ->where('visitor_token_hash', $attribution->visitor_token_hash)
            ->count();

        if ($conversionCount >= self::DUPLICATE_FINGERPRINT_THRESHOLD) {
            $this->flag($attribution, AffiliateFraudFlagType::DuplicateFingerprint, [
                'visitor_token_hash' => $attribution->visitor_token_hash,
                'conversion_count' => $conversionCount,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function flag(AffiliateAttribution $attribution, AffiliateFraudFlagType $type, array $details): void
    {
        AffiliateFraudFlag::create([
            'tenant_id' => $attribution->tenant_id,
            'affiliate_id' => $attribution->affiliate_id,
            'flag_type' => $type,
            'detected_at' => CarbonImmutable::now(),
            'details' => $details,
        ]);
    }
}
