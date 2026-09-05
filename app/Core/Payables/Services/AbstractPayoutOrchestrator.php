<?php

declare(strict_types=1);

namespace App\Core\Payables\Services;

use App\Core\Payables\Contracts\PayableBalanceProviderInterface;
use App\Core\Payables\Contracts\PayoutConcurrencyBarrierInterface;
use App\Core\Payables\Enums\PayableAvailabilityStatus;
use App\Core\Payables\Enums\PayableEntryType;
use App\Core\Payables\Enums\PayoutAllocationStatus;
use App\Core\Payables\Enums\PayoutRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one payout request/allocation/batching/mark-paid state machine shared by
 * every payable beneficiary type (Vendor, Affiliate, ...). Extracted verbatim
 * from Modules\Marketplace\Services\PayoutService (the original, still-correct
 * implementation) during the Phase-19 Owner Delta, which required a single
 * reusable payout orchestration layer rather than a second hand-copied engine.
 *
 * Domain-owned payable subledgers (VendorPayableEntry, AffiliatePayableEntry)
 * remain separate per-beneficiary tables — only the request/allocate/batch/
 * finalize/cancel/fail lifecycle logic is unified here. Each concrete
 * subclass supplies its own Eloquent model classes, beneficiary eligibility
 * check, and exception types via the abstract hooks below; it must not
 * reimplement the locking/allocation/idempotency algorithm itself.
 *
 * Operates purely through Eloquent's typed getAttribute()/setAttribute() API
 * rather than magic properties, since this class deliberately works across
 * more than one concrete Model subtype and cannot statically know either
 * one's declared properties.
 */
abstract class AbstractPayoutOrchestrator
{
    public function __construct(
        protected readonly PayableBalanceProviderInterface $balanceProvider,
        protected readonly PayoutConcurrencyBarrierInterface $barrier,
    ) {}

    /** @return class-string<Model> */
    abstract protected function payoutRequestModelClass(): string;

    /** @return class-string<Model> */
    abstract protected function payoutRequestAllocationModelClass(): string;

    /** @return class-string<Model> */
    abstract protected function payableEntryModelClass(): string;

    abstract protected function beneficiaryColumn(): string;

    abstract protected function allocationEntryColumn(): string;

    /**
     * Locks and validates the beneficiary aggregate row (e.g. Vendor,
     * Affiliate) for payout eligibility. Must throw a beneficiary-specific
     * domain exception (not found / not operationally active) — the
     * orchestrator does not know or care which exception type is thrown.
     */
    abstract protected function assertBeneficiaryEligibleForPayout(int $tenantId, int $beneficiaryId): void;

    /** @return never */
    abstract protected function throwInsufficientBalance(int $requestedMinor, int $availableMinor, string $currency): mixed;

    /** @return never */
    abstract protected function throwAllocationMismatch(int $requestedMinor, int $allocatedMinor): mixed;

    /** @return never */
    abstract protected function throwInvalidState(string $message): mixed;

    /** @return never */
    abstract protected function throwNotProcessingForFinalization(string $currentStatus): mixed;

    /** @return never */
    abstract protected function throwAllocationsNotReserved(): mixed;

    /**
     * @param  array<string, mixed>  $destinationDetails
     */
    final protected function doRequestPayout(
        int $tenantId,
        int $beneficiaryId,
        int $amountMinor,
        string $currency,
        array $destinationDetails = []
    ): Model {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException("Payout request amount must be strictly positive (got: {$amountMinor}).");
        }

        return DB::transaction(function () use ($tenantId, $beneficiaryId, $amountMinor, $currency, $destinationDetails): Model {
            $this->assertBeneficiaryEligibleForPayout($tenantId, $beneficiaryId);
            $this->barrier->wait('payout_request_beneficiary_locked');

            $entryModel = $this->payableEntryModelClass();
            $beneficiaryColumn = $this->beneficiaryColumn();

            $candidateEntries = $entryModel::where('tenant_id', $tenantId)
                ->where($beneficiaryColumn, $beneficiaryId)
                ->where('currency', $currency)
                ->whereIn('entry_type', [PayableEntryType::Earning->value, PayableEntryType::ManualAdjustmentCredit->value])
                ->where('availability_status', PayableAvailabilityStatus::Available->value)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $withdrawableMinor = $this->balanceProvider->getWithdrawableBalanceMinor($tenantId, $beneficiaryId, $currency);
            if ($withdrawableMinor < $amountMinor) {
                $this->throwInsufficientBalance($amountMinor, $withdrawableMinor, $currency);
            }

            $requestModel = $this->payoutRequestModelClass();
            $payoutRequest = $requestModel::create([
                'tenant_id' => $tenantId,
                $beneficiaryColumn => $beneficiaryId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => PayoutRequestStatus::Requested,
                'destination_details' => $destinationDetails,
            ]);

            $allocationModel = $this->payoutRequestAllocationModelClass();
            $allocationEntryColumn = $this->allocationEntryColumn();

            $remainingToReserve = $amountMinor;
            $totalReserved = 0;

            foreach ($candidateEntries as $entry) {
                if ($remainingToReserve <= 0) {
                    break;
                }

                $entryId = (int) $entry->getAttribute('id');
                $availableOnEntry = $this->balanceProvider->getSourceRemainingAllocatableMinor($entryId);
                if ($availableOnEntry <= 0) {
                    continue;
                }

                $allocationMinor = min($remainingToReserve, $availableOnEntry);

                $allocationModel::create([
                    'tenant_id' => $tenantId,
                    'payout_request_id' => $payoutRequest->getAttribute('id'),
                    $allocationEntryColumn => $entryId,
                    'allocated_amount_minor' => $allocationMinor,
                    'status' => PayoutAllocationStatus::Reserved,
                ]);

                $remainingToReserve -= $allocationMinor;
                $totalReserved += $allocationMinor;
            }

            if ($totalReserved !== $amountMinor) {
                $this->throwAllocationMismatch($amountMinor, $totalReserved);
            }

            return $payoutRequest;
        });
    }

    final protected function doApprovePayout(int $payoutRequestId, int $approvedByUserId): Model
    {
        return DB::transaction(function () use ($payoutRequestId, $approvedByUserId): Model {
            $requestModel = $this->payoutRequestModelClass();
            $request = $requestModel::lockForUpdate()->findOrFail($payoutRequestId);

            $status = $request->getAttribute('status');
            if ($status !== PayoutRequestStatus::Requested) {
                $currentValue = $status instanceof PayoutRequestStatus ? $status->value : (string) $status;
                $this->throwInvalidState("Cannot approve payout request with status '{$currentValue}'.");
            }

            $request->setAttribute('status', PayoutRequestStatus::Approved);
            $request->setAttribute('approved_by_user_id', $approvedByUserId);
            $request->save();

            return $request;
        });
    }

    final protected function doMarkProcessing(int $payoutRequestId): Model
    {
        return DB::transaction(function () use ($payoutRequestId): Model {
            $requestModel = $this->payoutRequestModelClass();
            $request = $requestModel::lockForUpdate()->findOrFail($payoutRequestId);

            $status = $request->getAttribute('status');
            if ($status !== PayoutRequestStatus::Approved) {
                $currentValue = $status instanceof PayoutRequestStatus ? $status->value : (string) $status;
                $this->throwInvalidState("Cannot mark payout processing from status '{$currentValue}' (expected approved).");
            }

            $request->setAttribute('status', PayoutRequestStatus::Processing);
            $request->save();

            return $request;
        });
    }

    /**
     * @param  array<string, mixed>  $settlementMetadata
     */
    final protected function doFinalizePayout(int $payoutRequestId, string $settlementReference, array $settlementMetadata = []): Model
    {
        if (trim($settlementReference) === '') {
            throw new \InvalidArgumentException('A valid non-empty settlement reference is required to finalize payout.');
        }

        return DB::transaction(function () use ($payoutRequestId, $settlementReference, $settlementMetadata): Model {
            $requestModel = $this->payoutRequestModelClass();
            $request = $requestModel::lockForUpdate()->findOrFail($payoutRequestId);

            if ($request->getAttribute('status') === PayoutRequestStatus::Paid) {
                return $request;
            }

            $this->barrier->wait('payout_finalization_request_locked');

            $status = $request->getAttribute('status');
            if ($status !== PayoutRequestStatus::Processing) {
                $currentValue = $status instanceof PayoutRequestStatus ? $status->value : (string) $status;
                $this->throwNotProcessingForFinalization($currentValue);
            }

            $allocationModel = $this->payoutRequestAllocationModelClass();
            $requestId = $request->getAttribute('id');
            $allocations = $allocationModel::where('payout_request_id', $requestId)->lockForUpdate()->get();

            foreach ($allocations as $allocation) {
                if ($allocation->getAttribute('status') !== PayoutAllocationStatus::Reserved) {
                    $this->throwAllocationsNotReserved();
                }
            }

            $details = (array) ($request->getAttribute('destination_details') ?? []);
            $details['settlement'] = [
                'reference' => $settlementReference,
                'metadata' => $settlementMetadata,
                'settled_at' => CarbonImmutable::now()->toIso8601String(),
            ];
            $request->setAttribute('destination_details', $details);

            $entryModel = $this->payableEntryModelClass();
            $beneficiaryColumn = $this->beneficiaryColumn();

            $entryModel::create([
                'tenant_id' => $request->getAttribute('tenant_id'),
                $beneficiaryColumn => $request->getAttribute($beneficiaryColumn),
                'entry_type' => PayableEntryType::PayoutDisbursement,
                'source_type' => 'payout_request',
                'source_uuid' => $request->getAttribute('uuid'),
                'currency' => $request->getAttribute('currency'),
                'amount_minor' => $request->getAttribute('amount_minor'),
                'commission_amount_minor' => 0,
                'net_amount_minor' => $request->getAttribute('amount_minor'),
                'availability_status' => PayableAvailabilityStatus::Available,
                'available_at' => CarbonImmutable::now(),
            ]);

            foreach ($allocations as $allocation) {
                $allocation->setAttribute('status', PayoutAllocationStatus::Consumed);
                $allocation->save();
            }

            $request->setAttribute('status', PayoutRequestStatus::Paid);
            $request->setAttribute('paid_at', CarbonImmutable::now());
            $request->save();

            return $request;
        });
    }

    final protected function doCancelPayout(int $payoutRequestId): Model
    {
        return DB::transaction(function () use ($payoutRequestId): Model {
            $requestModel = $this->payoutRequestModelClass();
            $request = $requestModel::lockForUpdate()->findOrFail($payoutRequestId);

            $status = $request->getAttribute('status');
            if (! ($status instanceof PayoutRequestStatus) || ! $status->canCancel()) {
                $currentValue = $status instanceof PayoutRequestStatus ? $status->value : (string) $status;
                $this->throwInvalidState("Cannot cancel payout request with status '{$currentValue}'.");
            }

            $this->releaseReservedAllocations($request);

            $request->setAttribute('status', PayoutRequestStatus::Cancelled);
            $request->save();

            return $request;
        });
    }

    final protected function doFailPayout(int $payoutRequestId, string $reason): Model
    {
        return DB::transaction(function () use ($payoutRequestId, $reason): Model {
            $requestModel = $this->payoutRequestModelClass();
            $request = $requestModel::lockForUpdate()->findOrFail($payoutRequestId);

            if ($request->getAttribute('status') === PayoutRequestStatus::Paid) {
                $this->throwInvalidState('Cannot fail a payout request that is already marked paid.');
            }

            $this->releaseReservedAllocations($request);

            $dest = (array) ($request->getAttribute('destination_details') ?? []);
            $dest['failure_reason'] = $reason;
            $request->setAttribute('destination_details', $dest);
            $request->setAttribute('status', PayoutRequestStatus::Failed);
            $request->save();

            return $request;
        });
    }

    private function releaseReservedAllocations(Model $request): void
    {
        $allocationModel = $this->payoutRequestAllocationModelClass();
        $requestId = $request->getAttribute('id');

        $allocations = $allocationModel::where('payout_request_id', $requestId)->lockForUpdate()->get();
        foreach ($allocations as $allocation) {
            if ($allocation->getAttribute('status') === PayoutAllocationStatus::Reserved) {
                $allocation->setAttribute('status', PayoutAllocationStatus::Released);
                $allocation->save();
            }
        }
    }
}
