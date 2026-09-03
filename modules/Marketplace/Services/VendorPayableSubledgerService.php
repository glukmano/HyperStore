<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\MarketplaceCommercialPolicyInterface;
use Modules\Marketplace\Contracts\VendorPayableAvailabilityPolicyInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\DTOs\VendorBalanceDTO;
use Modules\Marketplace\Enums\PayoutAllocationStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorPayableAvailabilityStatus;
use Modules\Marketplace\Enums\VendorPayableEntryType;
use Modules\Marketplace\Exceptions\PayoutAllocationException;
use Modules\Marketplace\Models\PayoutRequestAllocation;
use Modules\Marketplace\Models\VendorPayableEntry;

final class VendorPayableSubledgerService implements VendorPayableSubledgerServiceInterface
{
    public function __construct(
        private readonly MarketplaceCommercialPolicyInterface $commercialPolicy,
        private readonly VendorPayableAvailabilityPolicyInterface $availabilityPolicy,
    ) {}

    public function accrueEarning(
        int $tenantId,
        int $vendorId,
        ?int $orderItemId,
        string $sourceType,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        int $commissionMinor,
        ?int $storeId = null
    ): ?VendorPayableEntry {
        if (! $this->commercialPolicy->doesPlatformOweVendorPayable($tenantId, $storeId)) {
            return null;
        }

        $netMinor = $amountMinor - $commissionMinor;
        $availableAt = $this->availabilityPolicy->calculateAvailableAt($tenantId, $storeId);

        /** @var VendorPayableEntry $entry */
        $entry = VendorPayableEntry::create([
            'tenant_id' => $tenantId,
            'vendor_id' => $vendorId,
            'order_item_id' => $orderItemId,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => $sourceType,
            'source_uuid' => $sourceUuid,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'commission_amount_minor' => $commissionMinor,
            'net_amount_minor' => $netMinor,
            'availability_status' => VendorPayableAvailabilityStatus::Pending,
            'available_at' => $availableAt,
        ]);

        return $entry;
    }

    public function accrueRefundAdjustment(
        int $tenantId,
        int $vendorId,
        ?int $orderItemId,
        string $sourceType,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        int $commissionMinor,
        ?int $storeId = null
    ): ?VendorPayableEntry {
        if (! $this->commercialPolicy->doesPlatformOweVendorPayable($tenantId, $storeId)) {
            return null;
        }

        $netMinor = $amountMinor - $commissionMinor;

        /** @var VendorPayableEntry $entry */
        $entry = VendorPayableEntry::create([
            'tenant_id' => $tenantId,
            'vendor_id' => $vendorId,
            'order_item_id' => $orderItemId,
            'entry_type' => VendorPayableEntryType::RefundAdjustment,
            'source_type' => $sourceType,
            'source_uuid' => $sourceUuid,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'commission_amount_minor' => $commissionMinor,
            'net_amount_minor' => $netMinor,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
            'available_at' => CarbonImmutable::now(),
        ]);

        return $entry;
    }

    public function recordManualAdjustment(
        int $tenantId,
        int $vendorId,
        string $type,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        string $reason
    ): VendorPayableEntry {
        $entryType = VendorPayableEntryType::from($type);

        /** @var VendorPayableEntry $entry */
        $entry = VendorPayableEntry::create([
            'tenant_id' => $tenantId,
            'vendor_id' => $vendorId,
            'order_item_id' => null,
            'entry_type' => $entryType,
            'source_type' => 'manual_adjustment',
            'source_uuid' => $sourceUuid,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'commission_amount_minor' => 0,
            'net_amount_minor' => $amountMinor,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
            'available_at' => CarbonImmutable::now(),
            'held_reason' => $reason,
        ]);

        return $entry;
    }

    public function maturePendingPayables(int $tenantId, ?CarbonImmutable $asOf = null): int
    {
        $cutoff = $asOf ?? CarbonImmutable::now();

        return DB::transaction(function () use ($tenantId, $cutoff): int {
            /** @var Collection<int, VendorPayableEntry> $pendingEntries */
            $pendingEntries = VendorPayableEntry::where('tenant_id', $tenantId)
                ->where('entry_type', VendorPayableEntryType::Earning->value)
                ->where('availability_status', VendorPayableAvailabilityStatus::Pending->value)
                ->where('available_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();

            $maturedCount = 0;
            foreach ($pendingEntries as $entry) {
                $vendor = $entry->vendor;
                if ($vendor->operational_status !== VendorOperationalStatus::Active) {
                    continue;
                }

                if ($entry->held_reason !== null || $entry->availability_status !== VendorPayableAvailabilityStatus::Pending) {
                    continue;
                }

                $entry->availability_status = VendorPayableAvailabilityStatus::Available;
                $entry->save();
                $maturedCount++;
            }

            return $maturedCount;
        });
    }

    public function holdEntry(int $entryId, string $reason): VendorPayableEntry
    {
        return DB::transaction(function () use ($entryId, $reason): VendorPayableEntry {
            /** @var VendorPayableEntry $entry */
            $entry = VendorPayableEntry::lockForUpdate()->findOrFail($entryId);

            if (! $entry->availability_status->canTransitionTo(VendorPayableAvailabilityStatus::Held)) {
                throw new \DomainException("Cannot transition entry from {$entry->availability_status->value} to held.");
            }

            $entry->availability_status = VendorPayableAvailabilityStatus::Held;
            $entry->held_reason = $reason;
            $entry->save();

            return $entry;
        });
    }

    public function releaseHold(int $entryId): VendorPayableEntry
    {
        return DB::transaction(function () use ($entryId): VendorPayableEntry {
            /** @var VendorPayableEntry $entry */
            $entry = VendorPayableEntry::lockForUpdate()->findOrFail($entryId);

            if ($entry->availability_status !== VendorPayableAvailabilityStatus::Held) {
                throw new \DomainException("Entry {$entryId} is not held.");
            }

            $now = CarbonImmutable::now();
            $targetStatus = ($entry->available_at !== null && $entry->available_at <= $now)
                ? VendorPayableAvailabilityStatus::Available
                : VendorPayableAvailabilityStatus::Pending;

            $entry->availability_status = $targetStatus;
            $entry->held_reason = null;
            $entry->save();

            return $entry;
        });
    }

    public function getBalances(int $tenantId, int $vendorId, string $currency): VendorBalanceDTO
    {
        $pendingMinor = (int) VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('currency', $currency)
            ->where('entry_type', VendorPayableEntryType::Earning->value)
            ->where('availability_status', VendorPayableAvailabilityStatus::Pending->value)
            ->sum('net_amount_minor');

        $heldMinor = (int) VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('currency', $currency)
            ->where('availability_status', VendorPayableAvailabilityStatus::Held->value)
            ->sum('net_amount_minor');

        $earningsMinor = (int) VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('currency', $currency)
            ->where('entry_type', VendorPayableEntryType::Earning->value)
            ->where('availability_status', VendorPayableAvailabilityStatus::Available->value)
            ->sum('net_amount_minor');

        $manualCreditsMinor = (int) VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('currency', $currency)
            ->where('entry_type', VendorPayableEntryType::ManualAdjustmentCredit->value)
            ->where('availability_status', VendorPayableAvailabilityStatus::Available->value)
            ->sum('net_amount_minor');

        $refundAdjustmentsMinor = (int) VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('currency', $currency)
            ->where('entry_type', VendorPayableEntryType::RefundAdjustment->value)
            ->sum('net_amount_minor');

        $manualDebitsMinor = (int) VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('currency', $currency)
            ->where('entry_type', VendorPayableEntryType::ManualAdjustmentDebit->value)
            ->sum('net_amount_minor');

        $payoutDisbursementsMinor = (int) VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('currency', $currency)
            ->where('entry_type', VendorPayableEntryType::PayoutDisbursement->value)
            ->sum('net_amount_minor');

        $availableEconomicMinor = ($earningsMinor + $manualCreditsMinor) - ($refundAdjustmentsMinor + $manualDebitsMinor + $payoutDisbursementsMinor);

        $reservedForPayoutMinor = (int) PayoutRequestAllocation::where('tenant_id', $tenantId)
            ->whereHas('request', function ($q) use ($vendorId, $currency): void {
                $q->where('vendor_id', $vendorId)->where('currency', $currency);
            })
            ->where('status', PayoutAllocationStatus::Reserved->value)
            ->sum('allocated_amount_minor');

        $withdrawableMinor = max(0, $availableEconomicMinor - $reservedForPayoutMinor);

        return new VendorBalanceDTO(
            pendingBalanceMinor: $pendingMinor,
            heldBalanceMinor: $heldMinor,
            availableEconomicBalanceMinor: $availableEconomicMinor,
            reservedForPayoutMinor: $reservedForPayoutMinor,
            withdrawableBalanceMinor: $withdrawableMinor,
            currency: $currency,
        );
    }

    public function getSourceRemainingAllocatableMinor(int $payableEntryId): int
    {
        /** @var VendorPayableEntry|null $entry */
        $entry = VendorPayableEntry::find($payableEntryId);
        if ($entry === null) {
            return 0;
        }

        if (! $entry->entry_type->isAllocatableSource()) {
            throw PayoutAllocationException::invalidSourceType($entry->entry_type->value);
        }

        if ($entry->availability_status !== VendorPayableAvailabilityStatus::Available) {
            return 0;
        }

        $allocatedMinor = (int) PayoutRequestAllocation::where('vendor_payable_entry_id', $payableEntryId)
            ->whereIn('status', [PayoutAllocationStatus::Reserved->value, PayoutAllocationStatus::Consumed->value])
            ->sum('allocated_amount_minor');

        return max(0, $entry->net_amount_minor - $allocatedMinor);
    }
}
