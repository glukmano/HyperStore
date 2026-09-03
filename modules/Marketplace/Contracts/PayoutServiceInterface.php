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

    public function finalizePayout(int $payoutRequestId): PayoutRequest;

    public function cancelPayout(int $payoutRequestId): PayoutRequest;

    public function failPayout(int $payoutRequestId, string $reason): PayoutRequest;
}
