<?php

declare(strict_types=1);

namespace Modules\Affiliate\Services;

use App\Core\Payables\Services\AbstractPayoutOrchestrator;
use App\Core\Payables\Services\NullPayoutConcurrencyBarrier;
use Modules\Affiliate\Contracts\AffiliatePayableSubledgerServiceInterface;
use Modules\Affiliate\Contracts\AffiliatePayoutServiceInterface;
use Modules\Affiliate\Enums\AffiliateStatus;
use Modules\Affiliate\Exceptions\AffiliateNotFoundException;
use Modules\Affiliate\Exceptions\AffiliateOperationalStatusException;
use Modules\Affiliate\Exceptions\AffiliatePayoutAllocationException;
use Modules\Affiliate\Exceptions\AffiliatePayoutFinalizationException;
use Modules\Affiliate\Exceptions\InsufficientAffiliatePayableBalanceException;
use Modules\Affiliate\Models\Affiliate;
use Modules\Affiliate\Models\AffiliatePayableEntry;
use Modules\Affiliate\Models\AffiliatePayoutRequest;
use Modules\Affiliate\Models\AffiliatePayoutRequestAllocation;

/**
 * Thin Affiliate-specific adapter over the shared AbstractPayoutOrchestrator
 * — the mirror image of Modules\Marketplace\Services\PayoutService. Owner
 * Delta correction §1: the request/allocate/finalize/cancel/fail state
 * machine itself is not duplicated here, only the beneficiary-specific hooks.
 */
final class AffiliatePayoutService extends AbstractPayoutOrchestrator implements AffiliatePayoutServiceInterface
{
    public function __construct(
        private readonly AffiliatePayableSubledgerServiceInterface $subledgerService,
    ) {
        parent::__construct($this->subledgerService, new NullPayoutConcurrencyBarrier);
    }

    public function requestPayout(int $tenantId, int $affiliateId, int $amountMinor, string $currency, array $destinationDetails = []): AffiliatePayoutRequest
    {
        /** @var AffiliatePayoutRequest $request */
        $request = $this->doRequestPayout($tenantId, $affiliateId, $amountMinor, $currency, $destinationDetails);

        return $request;
    }

    public function approvePayout(int $payoutRequestId, int $approvedByUserId): AffiliatePayoutRequest
    {
        /** @var AffiliatePayoutRequest $request */
        $request = $this->doApprovePayout($payoutRequestId, $approvedByUserId);

        return $request;
    }

    public function markProcessing(int $payoutRequestId): AffiliatePayoutRequest
    {
        /** @var AffiliatePayoutRequest $request */
        $request = $this->doMarkProcessing($payoutRequestId);

        return $request;
    }

    public function finalizePayout(int $payoutRequestId, string $settlementReference, array $settlementMetadata = []): AffiliatePayoutRequest
    {
        /** @var AffiliatePayoutRequest $request */
        $request = $this->doFinalizePayout($payoutRequestId, $settlementReference, $settlementMetadata);

        return $request;
    }

    public function cancelPayout(int $payoutRequestId): AffiliatePayoutRequest
    {
        /** @var AffiliatePayoutRequest $request */
        $request = $this->doCancelPayout($payoutRequestId);

        return $request;
    }

    public function failPayout(int $payoutRequestId, string $reason): AffiliatePayoutRequest
    {
        /** @var AffiliatePayoutRequest $request */
        $request = $this->doFailPayout($payoutRequestId, $reason);

        return $request;
    }

    protected function payoutRequestModelClass(): string
    {
        return AffiliatePayoutRequest::class;
    }

    protected function payoutRequestAllocationModelClass(): string
    {
        return AffiliatePayoutRequestAllocation::class;
    }

    protected function payableEntryModelClass(): string
    {
        return AffiliatePayableEntry::class;
    }

    protected function beneficiaryColumn(): string
    {
        return 'affiliate_id';
    }

    protected function allocationEntryColumn(): string
    {
        return 'affiliate_payable_entry_id';
    }

    protected function assertBeneficiaryEligibleForPayout(int $tenantId, int $beneficiaryId): void
    {
        /** @var Affiliate|null $affiliate */
        $affiliate = Affiliate::where('tenant_id', $tenantId)->lockForUpdate()->find($beneficiaryId);
        if ($affiliate === null) {
            throw new AffiliateNotFoundException("Affiliate {$beneficiaryId} not found for tenant {$tenantId}.");
        }

        if ($affiliate->status !== AffiliateStatus::Active) {
            throw AffiliateOperationalStatusException::affiliateNotActive($affiliate->uuid, $affiliate->status->value);
        }
    }

    protected function throwInsufficientBalance(int $requestedMinor, int $availableMinor, string $currency): never
    {
        throw InsufficientAffiliatePayableBalanceException::forAmount($requestedMinor, $availableMinor, $currency);
    }

    protected function throwAllocationMismatch(int $requestedMinor, int $allocatedMinor): never
    {
        throw AffiliatePayoutAllocationException::allocationMismatch($requestedMinor, $allocatedMinor);
    }

    protected function throwInvalidState(string $message): never
    {
        throw new AffiliatePayoutFinalizationException($message);
    }

    protected function throwNotProcessingForFinalization(string $currentStatus): never
    {
        throw AffiliatePayoutFinalizationException::notProcessing($currentStatus);
    }

    protected function throwAllocationsNotReserved(): never
    {
        throw AffiliatePayoutFinalizationException::allocationsNotReserved();
    }
}
