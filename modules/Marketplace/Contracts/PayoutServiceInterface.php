<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Models\PayoutRequest;

interface PayoutServiceInterface
{
    /**
     * @param  array<string, mixed>  $destinationDetails
     */
    public function requestPayout(int $tenantId, int $vendorId, int $amountMinor, string $currency, array $destinationDetails = []): PayoutRequest;

    public function approvePayout(int $payoutRequestId, int $approvedByUserId): PayoutRequest;

    public function markProcessing(int $payoutRequestId): PayoutRequest;

    /**
     * @param  array<string, mixed>  $settlementMetadata
     */
    public function finalizePayout(int $payoutRequestId, string $settlementReference, array $settlementMetadata = []): PayoutRequest;

    public function cancelPayout(int $payoutRequestId): PayoutRequest;

    public function failPayout(int $payoutRequestId, string $reason): PayoutRequest;
}
