<?php

declare(strict_types=1);

namespace Modules\Affiliate\Contracts;

use Modules\Affiliate\Models\AffiliatePayoutRequest;

/**
 * Mirrors Modules\Marketplace\Contracts\PayoutServiceInterface exactly — both
 * are thin adapters over the one shared App\Core\Payables\Services\AbstractPayoutOrchestrator.
 */
interface AffiliatePayoutServiceInterface
{
    /**
     * @param  array<string, mixed>  $destinationDetails
     */
    public function requestPayout(int $tenantId, int $affiliateId, int $amountMinor, string $currency, array $destinationDetails = []): AffiliatePayoutRequest;

    public function approvePayout(int $payoutRequestId, int $approvedByUserId): AffiliatePayoutRequest;

    public function markProcessing(int $payoutRequestId): AffiliatePayoutRequest;

    /**
     * @param  array<string, mixed>  $settlementMetadata
     */
    public function finalizePayout(int $payoutRequestId, string $settlementReference, array $settlementMetadata = []): AffiliatePayoutRequest;

    public function cancelPayout(int $payoutRequestId): AffiliatePayoutRequest;

    public function failPayout(int $payoutRequestId, string $reason): AffiliatePayoutRequest;
}
