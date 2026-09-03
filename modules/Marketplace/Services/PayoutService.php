<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\PayoutServiceInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\Enums\PayoutAllocationStatus;
use Modules\Marketplace\Enums\PayoutRequestStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorPayableAvailabilityStatus;
use Modules\Marketplace\Enums\VendorPayableEntryType;
use Modules\Marketplace\Exceptions\InsufficientPayableBalanceException;
use Modules\Marketplace\Exceptions\PayoutAllocationException;
use Modules\Marketplace\Exceptions\PayoutFinalizationException;
use Modules\Marketplace\Exceptions\VendorNotFoundException;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\PayoutRequest;
use Modules\Marketplace\Models\PayoutRequestAllocation;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPayableEntry;

final class PayoutService implements PayoutServiceInterface
{
    public function __construct(
        private readonly VendorPayableSubledgerServiceInterface $subledgerService,
        private readonly MarketplaceConcurrencyBarrierInterface $barrier,
    ) {}

    public function requestPayout(
        int $tenantId,
        int $vendorId,
        int $amountMinor,
        string $currency,
        array $destinationDetails = []
    ): PayoutRequest {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException("Payout request amount must be strictly positive (got: {$amountMinor}).");
        }

        return DB::transaction(function () use ($tenantId, $vendorId, $amountMinor, $currency, $destinationDetails): PayoutRequest {
            // 1. Lock Vendor aggregate row to ensure operational eligibility
            /** @var Vendor|null $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->find($vendorId);
            if ($vendor === null) {
                throw new VendorNotFoundException("Vendor {$vendorId} not found for tenant {$tenantId}.");
            }

            if ($vendor->operational_status !== VendorOperationalStatus::Active) {
                throw VendorOperationalStatusException::vendorNotActive($vendor->uuid, $vendor->operational_status->value);
            }

            $this->barrier->wait('payout_request_vendor_locked');

            // 2. Lock candidate eligible entries in deterministic ID order
            $candidateEntries = VendorPayableEntry::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('currency', $currency)
                ->whereIn('entry_type', [VendorPayableEntryType::Earning->value, VendorPayableEntryType::ManualAdjustmentCredit->value])
                ->where('availability_status', VendorPayableAvailabilityStatus::Available->value)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // Check current withdrawable balance under lock
            $balances = $this->subledgerService->getBalances($tenantId, $vendorId, $currency);
            if ($balances->withdrawableBalanceMinor < $amountMinor) {
                throw InsufficientPayableBalanceException::forAmount($amountMinor, $balances->withdrawableBalanceMinor, $currency);
            }

            // 3. Create PayoutRequest in requested state
            /** @var PayoutRequest $payoutRequest */
            $payoutRequest = PayoutRequest::create([
                'tenant_id' => $tenantId,
                'vendor_id' => $vendorId,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => PayoutRequestStatus::Requested,
                'destination_details' => $destinationDetails,
            ]);

            // 4. Allocate reservation against entries
            $remainingToReserve = $amountMinor;
            $totalReserved = 0;

            foreach ($candidateEntries as $entry) {
                if ($remainingToReserve <= 0) {
                    break;
                }

                $availableOnEntry = $this->subledgerService->getSourceRemainingAllocatableMinor($entry->id);
                if ($availableOnEntry <= 0) {
                    continue;
                }

                $allocationMinor = min($remainingToReserve, $availableOnEntry);

                PayoutRequestAllocation::create([
                    'tenant_id' => $tenantId,
                    'payout_request_id' => $payoutRequest->id,
                    'vendor_payable_entry_id' => $entry->id,
                    'allocated_amount_minor' => $allocationMinor,
                    'status' => PayoutAllocationStatus::Reserved,
                ]);

                $remainingToReserve -= $allocationMinor;
                $totalReserved += $allocationMinor;
            }

            // 5. Assert total reserved equals requested amount; otherwise rollback
            if ($totalReserved !== $amountMinor) {
                throw PayoutAllocationException::allocationMismatch($amountMinor, $totalReserved);
            }

            return $payoutRequest;
        });
    }

    public function approvePayout(int $payoutRequestId, int $approvedByUserId): PayoutRequest
    {
        return DB::transaction(function () use ($payoutRequestId, $approvedByUserId): PayoutRequest {
            /** @var PayoutRequest $request */
            $request = PayoutRequest::lockForUpdate()->findOrFail($payoutRequestId);

            if ($request->status !== PayoutRequestStatus::Requested) {
                throw new PayoutFinalizationException("Cannot approve payout request with status '{$request->status->value}'.");
            }

            $request->status = PayoutRequestStatus::Approved;
            $request->approved_by_user_id = $approvedByUserId;
            $request->save();

            return $request;
        });
    }

    public function markProcessing(int $payoutRequestId): PayoutRequest
    {
        return DB::transaction(function () use ($payoutRequestId): PayoutRequest {
            /** @var PayoutRequest $request */
            $request = PayoutRequest::lockForUpdate()->findOrFail($payoutRequestId);

            if ($request->status !== PayoutRequestStatus::Approved) {
                throw new PayoutFinalizationException("Cannot mark payout processing from status '{$request->status->value}' (expected approved).");
            }

            $request->status = PayoutRequestStatus::Processing;
            $request->save();

            return $request;
        });
    }

    public function finalizePayout(int $payoutRequestId, string $settlementReference, array $settlementMetadata = []): PayoutRequest
    {
        if (trim($settlementReference) === '') {
            throw new \InvalidArgumentException('A valid non-empty settlement reference is required to finalize payout.');
        }

        return DB::transaction(function () use ($payoutRequestId, $settlementReference, $settlementMetadata): PayoutRequest {
            /** @var PayoutRequest $request */
            $request = PayoutRequest::lockForUpdate()->findOrFail($payoutRequestId);

            // Idempotency: If already paid, replay existing state cleanly!
            if ($request->status === PayoutRequestStatus::Paid) {
                return $request;
            }

            $this->barrier->wait('payout_finalization_request_locked');

            // Frozen lifecycle accepts ONLY processing
            if ($request->status !== PayoutRequestStatus::Processing) {
                throw PayoutFinalizationException::notProcessing($request->status->value);
            }

            // Verify all allocations are status = 'reserved'
            $allocations = $request->allocations()->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                if ($allocation->status !== PayoutAllocationStatus::Reserved) {
                    throw PayoutFinalizationException::allocationsNotReserved();
                }
            }

            // Persist settlement evidence
            $details = (array) ($request->destination_details ?? []);
            $details['settlement'] = [
                'reference' => $settlementReference,
                'metadata' => $settlementMetadata,
                'settled_at' => CarbonImmutable::now()->toIso8601String(),
            ];
            $request->destination_details = $details;

            // Append ONE payout_disbursement entry to vendor_payable_entries
            VendorPayableEntry::create([
                'tenant_id' => $request->tenant_id,
                'vendor_id' => $request->vendor_id,
                'order_item_id' => null,
                'entry_type' => VendorPayableEntryType::PayoutDisbursement,
                'source_type' => 'payout_request',
                'source_uuid' => $request->uuid,
                'currency' => $request->currency,
                'amount_minor' => $request->amount_minor,
                'commission_amount_minor' => 0,
                'net_amount_minor' => $request->amount_minor,
                'availability_status' => VendorPayableAvailabilityStatus::Available,
                'available_at' => CarbonImmutable::now(),
            ]);

            // Transition allocations from reserved -> consumed
            foreach ($allocations as $allocation) {
                $allocation->status = PayoutAllocationStatus::Consumed;
                $allocation->save();
            }

            // Transition payout request status -> paid
            $request->status = PayoutRequestStatus::Paid;
            $request->paid_at = CarbonImmutable::now();
            $request->save();

            return $request;
        });
    }

    public function cancelPayout(int $payoutRequestId): PayoutRequest
    {
        return DB::transaction(function () use ($payoutRequestId): PayoutRequest {
            /** @var PayoutRequest $request */
            $request = PayoutRequest::lockForUpdate()->findOrFail($payoutRequestId);

            if (! $request->status->canCancel()) {
                throw new PayoutFinalizationException("Cannot cancel payout request with status '{$request->status->value}'.");
            }

            // Release all reserved allocations
            foreach ($request->allocations()->lockForUpdate()->get() as $allocation) {
                if ($allocation->status === PayoutAllocationStatus::Reserved) {
                    $allocation->status = PayoutAllocationStatus::Released;
                    $allocation->save();
                }
            }

            $request->status = PayoutRequestStatus::Cancelled;
            $request->save();

            return $request;
        });
    }

    public function failPayout(int $payoutRequestId, string $reason): PayoutRequest
    {
        return DB::transaction(function () use ($payoutRequestId, $reason): PayoutRequest {
            /** @var PayoutRequest $request */
            $request = PayoutRequest::lockForUpdate()->findOrFail($payoutRequestId);

            if ($request->status === PayoutRequestStatus::Paid) {
                throw new PayoutFinalizationException('Cannot fail a payout request that is already marked paid.');
            }

            // Release all reserved allocations
            foreach ($request->allocations()->lockForUpdate()->get() as $allocation) {
                if ($allocation->status === PayoutAllocationStatus::Reserved) {
                    $allocation->status = PayoutAllocationStatus::Released;
                    $allocation->save();
                }
            }

            $dest = (array) ($request->destination_details ?? []);
            $dest['failure_reason'] = $reason;
            $request->destination_details = $dest;
            $request->status = PayoutRequestStatus::Failed;
            $request->save();

            return $request;
        });
    }
}
