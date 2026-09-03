<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Carbon\CarbonImmutable;
use Modules\Marketplace\Contracts\MarketplaceCommercialPolicyInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\DTOs\VendorBalanceDTO;
use Modules\Marketplace\Enums\PayoutAllocationStatus;
use Modules\Marketplace\Enums\VendorPayableAvailabilityStatus;
use Modules\Marketplace\Enums\VendorPayableEntryType;
use Modules\Marketplace\Exceptions\PayoutAllocationException;
use Modules\Marketplace\Models\PayoutRequestAllocation;
use Modules\Marketplace\Models\VendorPayableEntry;

final class VendorPayableSubledgerService implements VendorPayableSubledgerServiceInterface
{
    public function __construct(
        private readonly MarketplaceCommercialPolicyInterface $commercialPolicy
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
        // Policy-gating: If platform does NOT owe vendor payable (e.g. Seller-MoR), do NOT accrue!
        if (! $this->commercialPolicy->doesPlatformOweVendorPayable($tenantId, $storeId)) {
            return null;
        }

        $netMinor = $amountMinor - $commissionMinor;

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
            'availability_status' => VendorPayableAvailabilityStatus::Available,
            'available_at' => CarbonImmutable::now(),
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

    public function getBalances(int $tenantId, int $vendorId, string $currency): VendorBalanceDTO
    {
        // 1. Pending Balance: earnings with availability_status = 'pending'
        $pendingMinor = (int) VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('currency', $currency)
            ->where('entry_type', VendorPayableEntryType::Earning->value)
            ->where('availability_status', VendorPayableAvailabilityStatus::Pending->value)
            ->sum('net_amount_minor');

        // 2. Held Balance: any entry with availability_status = 'held'
        $heldMinor = (int) VendorPayableEntry::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('currency', $currency)
            ->where('availability_status', VendorPayableAvailabilityStatus::Held->value)
            ->sum('net_amount_minor');

        // 3. Global Available Economic Balance:
        // sum(credits) - sum(debits) for available entries
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

        // 4. Reserved for Payout: sum(payout_request_allocations.allocated_amount_minor where status = 'reserved')
        $reservedForPayoutMinor = (int) PayoutRequestAllocation::where('tenant_id', $tenantId)
            ->whereHas('request', function ($q) use ($vendorId, $currency): void {
                $q->where('vendor_id', $vendorId)->where('currency', $currency);
            })
            ->where('status', PayoutAllocationStatus::Reserved->value)
            ->sum('allocated_amount_minor');

        // 5. Withdrawable Balance: available economic balance - reserved for payout
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
