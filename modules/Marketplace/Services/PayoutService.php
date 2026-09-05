<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Core\Payables\Services\AbstractPayoutOrchestrator;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\PayoutServiceInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\InsufficientPayableBalanceException;
use Modules\Marketplace\Exceptions\PayoutAllocationException;
use Modules\Marketplace\Exceptions\PayoutFinalizationException;
use Modules\Marketplace\Exceptions\VendorNotFoundException;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\PayoutRequest;
use Modules\Marketplace\Models\PayoutRequestAllocation;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPayableEntry;

/**
 * Thin Vendor-specific adapter over the shared AbstractPayoutOrchestrator
 * (Phase-19 Owner Delta: "no second payout engine" — the request/allocate/
 * batch/finalize/cancel/fail state machine itself lives exactly once, in
 * App\Core\Payables\Services\AbstractPayoutOrchestrator). This class only
 * supplies Vendor-specific model classes, the Vendor eligibility check, and
 * Marketplace's existing exception types — every public method's observable
 * behavior is byte-for-byte identical to before this refactor.
 */
final class PayoutService extends AbstractPayoutOrchestrator implements PayoutServiceInterface
{
    public function __construct(
        private readonly VendorPayableSubledgerServiceInterface $subledgerService,
        MarketplaceConcurrencyBarrierInterface $barrier,
    ) {
        parent::__construct($this->subledgerService, $barrier);
    }

    /**
     * @param  array<string, mixed>  $destinationDetails
     */
    public function requestPayout(
        int $tenantId,
        int $vendorId,
        int $amountMinor,
        string $currency,
        array $destinationDetails = []
    ): PayoutRequest {
        /** @var PayoutRequest $request */
        $request = $this->doRequestPayout($tenantId, $vendorId, $amountMinor, $currency, $destinationDetails);

        return $request;
    }

    public function approvePayout(int $payoutRequestId, int $approvedByUserId): PayoutRequest
    {
        /** @var PayoutRequest $request */
        $request = $this->doApprovePayout($payoutRequestId, $approvedByUserId);

        return $request;
    }

    public function markProcessing(int $payoutRequestId): PayoutRequest
    {
        /** @var PayoutRequest $request */
        $request = $this->doMarkProcessing($payoutRequestId);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $settlementMetadata
     */
    public function finalizePayout(int $payoutRequestId, string $settlementReference, array $settlementMetadata = []): PayoutRequest
    {
        /** @var PayoutRequest $request */
        $request = $this->doFinalizePayout($payoutRequestId, $settlementReference, $settlementMetadata);

        return $request;
    }

    public function cancelPayout(int $payoutRequestId): PayoutRequest
    {
        /** @var PayoutRequest $request */
        $request = $this->doCancelPayout($payoutRequestId);

        return $request;
    }

    public function failPayout(int $payoutRequestId, string $reason): PayoutRequest
    {
        /** @var PayoutRequest $request */
        $request = $this->doFailPayout($payoutRequestId, $reason);

        return $request;
    }

    protected function payoutRequestModelClass(): string
    {
        return PayoutRequest::class;
    }

    protected function payoutRequestAllocationModelClass(): string
    {
        return PayoutRequestAllocation::class;
    }

    protected function payableEntryModelClass(): string
    {
        return VendorPayableEntry::class;
    }

    protected function beneficiaryColumn(): string
    {
        return 'vendor_id';
    }

    protected function allocationEntryColumn(): string
    {
        return 'vendor_payable_entry_id';
    }

    protected function assertBeneficiaryEligibleForPayout(int $tenantId, int $beneficiaryId): void
    {
        /** @var Vendor|null $vendor */
        $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->find($beneficiaryId);
        if ($vendor === null) {
            throw new VendorNotFoundException("Vendor {$beneficiaryId} not found for tenant {$tenantId}.");
        }

        if ($vendor->operational_status !== VendorOperationalStatus::Active) {
            throw VendorOperationalStatusException::vendorNotActive($vendor->uuid, $vendor->operational_status->value);
        }
    }

    protected function throwInsufficientBalance(int $requestedMinor, int $availableMinor, string $currency): never
    {
        throw InsufficientPayableBalanceException::forAmount($requestedMinor, $availableMinor, $currency);
    }

    protected function throwAllocationMismatch(int $requestedMinor, int $allocatedMinor): never
    {
        throw PayoutAllocationException::allocationMismatch($requestedMinor, $allocatedMinor);
    }

    protected function throwInvalidState(string $message): never
    {
        throw new PayoutFinalizationException($message);
    }

    protected function throwNotProcessingForFinalization(string $currentStatus): never
    {
        throw PayoutFinalizationException::notProcessing($currentStatus);
    }

    protected function throwAllocationsNotReserved(): never
    {
        throw PayoutFinalizationException::allocationsNotReserved();
    }
}
