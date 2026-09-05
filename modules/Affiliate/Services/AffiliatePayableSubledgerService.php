<?php

declare(strict_types=1);

namespace Modules\Affiliate\Services;

use App\Core\Payables\Enums\PayableAvailabilityStatus;
use App\Core\Payables\Enums\PayableEntryType;
use App\Core\Payables\Enums\PayoutAllocationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Affiliate\Contracts\AffiliatePayableSubledgerServiceInterface;
use Modules\Affiliate\DTOs\AffiliateBalanceDTO;
use Modules\Affiliate\Enums\AffiliateStatus;
use Modules\Affiliate\Models\Affiliate;
use Modules\Affiliate\Models\AffiliatePayableEntry;
use Modules\Affiliate\Models\AffiliatePayoutRequestAllocation;

/**
 * Mirrors Modules\Marketplace\Services\VendorPayableSubledgerService exactly
 * — a self-contained per-Affiliate accrual subledger, never posting into
 * App\Core\Payables beyond the shared enums, never touching modules/Ledger.
 */
final class AffiliatePayableSubledgerService implements AffiliatePayableSubledgerServiceInterface
{
    public function accrueEarning(
        int $tenantId,
        int $affiliateId,
        ?int $affiliateConversionItemId,
        string $sourceType,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        int $commissionMinor
    ): AffiliatePayableEntry {
        // Idempotency: replaying the same source event returns the existing entry.
        $existing = AffiliatePayableEntry::where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_uuid', $sourceUuid)
            ->where('entry_type', PayableEntryType::Earning->value)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $netMinor = $amountMinor - $commissionMinor;

        return AffiliatePayableEntry::create([
            'tenant_id' => $tenantId,
            'affiliate_id' => $affiliateId,
            'affiliate_conversion_item_id' => $affiliateConversionItemId,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => $sourceType,
            'source_uuid' => $sourceUuid,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'commission_amount_minor' => $commissionMinor,
            'net_amount_minor' => $netMinor,
            'availability_status' => PayableAvailabilityStatus::Pending,
            'available_at' => CarbonImmutable::now(),
        ]);
    }

    public function accrueRefundAdjustment(
        int $tenantId,
        int $affiliateId,
        ?int $affiliateConversionItemId,
        string $sourceType,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        int $commissionMinor
    ): ?AffiliatePayableEntry {
        Affiliate::where('tenant_id', $tenantId)
            ->where('id', $affiliateId)
            ->lockForUpdate()
            ->firstOrFail();

        $existing = AffiliatePayableEntry::where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_uuid', $sourceUuid)
            ->where('entry_type', PayableEntryType::RefundAdjustment->value)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($amountMinor <= 0) {
            return null;
        }

        $netMinor = $amountMinor - $commissionMinor;

        return AffiliatePayableEntry::create([
            'tenant_id' => $tenantId,
            'affiliate_id' => $affiliateId,
            'affiliate_conversion_item_id' => $affiliateConversionItemId,
            'entry_type' => PayableEntryType::RefundAdjustment,
            'source_type' => $sourceType,
            'source_uuid' => $sourceUuid,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'commission_amount_minor' => $commissionMinor,
            'net_amount_minor' => $netMinor,
            'availability_status' => PayableAvailabilityStatus::Available,
            'available_at' => CarbonImmutable::now(),
        ]);
    }

    public function recordManualAdjustment(
        int $tenantId,
        int $affiliateId,
        string $type,
        string $sourceUuid,
        string $currency,
        int $amountMinor,
        string $reason
    ): AffiliatePayableEntry {
        $entryType = PayableEntryType::from($type);

        return AffiliatePayableEntry::create([
            'tenant_id' => $tenantId,
            'affiliate_id' => $affiliateId,
            'affiliate_conversion_item_id' => null,
            'entry_type' => $entryType,
            'source_type' => 'manual_adjustment',
            'source_uuid' => $sourceUuid,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'commission_amount_minor' => 0,
            'net_amount_minor' => $amountMinor,
            'availability_status' => PayableAvailabilityStatus::Available,
            'available_at' => CarbonImmutable::now(),
            'held_reason' => $reason,
        ]);
    }

    public function maturePendingPayables(int $tenantId, ?CarbonImmutable $asOf = null): int
    {
        $cutoff = $asOf ?? CarbonImmutable::now();

        return DB::transaction(function () use ($tenantId, $cutoff): int {
            $pendingEntries = AffiliatePayableEntry::where('tenant_id', $tenantId)
                ->where('entry_type', PayableEntryType::Earning->value)
                ->where('availability_status', PayableAvailabilityStatus::Pending->value)
                ->where('available_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();

            $maturedCount = 0;
            foreach ($pendingEntries as $entry) {
                $affiliate = $entry->affiliate;
                if ($affiliate->status !== AffiliateStatus::Active) {
                    continue;
                }

                if ($entry->held_reason !== null || $entry->availability_status !== PayableAvailabilityStatus::Pending) {
                    continue;
                }

                $entry->availability_status = PayableAvailabilityStatus::Available;
                $entry->save();
                $maturedCount++;
            }

            return $maturedCount;
        });
    }

    public function holdEntry(int $entryId, string $reason): AffiliatePayableEntry
    {
        return DB::transaction(function () use ($entryId, $reason): AffiliatePayableEntry {
            /** @var AffiliatePayableEntry $entry */
            $entry = AffiliatePayableEntry::lockForUpdate()->findOrFail($entryId);

            if (! $entry->availability_status->canTransitionTo(PayableAvailabilityStatus::Held)) {
                throw new \DomainException("Cannot transition entry from {$entry->availability_status->value} to held.");
            }

            $entry->availability_status = PayableAvailabilityStatus::Held;
            $entry->held_reason = $reason;
            $entry->save();

            return $entry;
        });
    }

    public function releaseHold(int $entryId): AffiliatePayableEntry
    {
        return DB::transaction(function () use ($entryId): AffiliatePayableEntry {
            /** @var AffiliatePayableEntry $entry */
            $entry = AffiliatePayableEntry::lockForUpdate()->findOrFail($entryId);

            if ($entry->availability_status !== PayableAvailabilityStatus::Held) {
                throw new \DomainException("Entry {$entryId} is not held.");
            }

            $now = CarbonImmutable::now();
            $targetStatus = ($entry->available_at !== null && $entry->available_at <= $now)
                ? PayableAvailabilityStatus::Available
                : PayableAvailabilityStatus::Pending;

            $entry->availability_status = $targetStatus;
            $entry->held_reason = null;
            $entry->save();

            return $entry;
        });
    }

    public function getWithdrawableBalanceMinor(int $tenantId, int $beneficiaryId, string $currency): int
    {
        return $this->getBalances($tenantId, $beneficiaryId, $currency)->withdrawableBalanceMinor;
    }

    public function getBalances(int $tenantId, int $affiliateId, string $currency): AffiliateBalanceDTO
    {
        $pendingMinor = (int) AffiliatePayableEntry::where('tenant_id', $tenantId)
            ->where('affiliate_id', $affiliateId)
            ->where('currency', $currency)
            ->where('entry_type', PayableEntryType::Earning->value)
            ->where('availability_status', PayableAvailabilityStatus::Pending->value)
            ->sum('net_amount_minor');

        $heldMinor = (int) AffiliatePayableEntry::where('tenant_id', $tenantId)
            ->where('affiliate_id', $affiliateId)
            ->where('currency', $currency)
            ->where('availability_status', PayableAvailabilityStatus::Held->value)
            ->sum('net_amount_minor');

        $earningsMinor = (int) AffiliatePayableEntry::where('tenant_id', $tenantId)
            ->where('affiliate_id', $affiliateId)
            ->where('currency', $currency)
            ->where('entry_type', PayableEntryType::Earning->value)
            ->where('availability_status', PayableAvailabilityStatus::Available->value)
            ->sum('net_amount_minor');

        $manualCreditsMinor = (int) AffiliatePayableEntry::where('tenant_id', $tenantId)
            ->where('affiliate_id', $affiliateId)
            ->where('currency', $currency)
            ->where('entry_type', PayableEntryType::ManualAdjustmentCredit->value)
            ->where('availability_status', PayableAvailabilityStatus::Available->value)
            ->sum('net_amount_minor');

        $refundAdjustmentsMinor = (int) AffiliatePayableEntry::where('tenant_id', $tenantId)
            ->where('affiliate_id', $affiliateId)
            ->where('currency', $currency)
            ->where('entry_type', PayableEntryType::RefundAdjustment->value)
            ->sum('net_amount_minor');

        $manualDebitsMinor = (int) AffiliatePayableEntry::where('tenant_id', $tenantId)
            ->where('affiliate_id', $affiliateId)
            ->where('currency', $currency)
            ->where('entry_type', PayableEntryType::ManualAdjustmentDebit->value)
            ->sum('net_amount_minor');

        $payoutDisbursementsMinor = (int) AffiliatePayableEntry::where('tenant_id', $tenantId)
            ->where('affiliate_id', $affiliateId)
            ->where('currency', $currency)
            ->where('entry_type', PayableEntryType::PayoutDisbursement->value)
            ->sum('net_amount_minor');

        $availableEconomicMinor = ($earningsMinor + $manualCreditsMinor) - ($refundAdjustmentsMinor + $manualDebitsMinor + $payoutDisbursementsMinor);

        $reservedForPayoutMinor = (int) AffiliatePayoutRequestAllocation::where('tenant_id', $tenantId)
            ->whereHas('request', function ($q) use ($affiliateId, $currency): void {
                $q->where('affiliate_id', $affiliateId)->where('currency', $currency);
            })
            ->where('status', PayoutAllocationStatus::Reserved->value)
            ->sum('allocated_amount_minor');

        $withdrawableMinor = max(0, $availableEconomicMinor - $reservedForPayoutMinor);

        return new AffiliateBalanceDTO(
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
        /** @var AffiliatePayableEntry|null $entry */
        $entry = AffiliatePayableEntry::find($payableEntryId);
        if ($entry === null) {
            return 0;
        }

        if (! $entry->entry_type->isAllocatableSource()) {
            return 0;
        }

        if ($entry->availability_status !== PayableAvailabilityStatus::Available) {
            return 0;
        }

        $allocatedMinor = (int) AffiliatePayoutRequestAllocation::where('affiliate_payable_entry_id', $payableEntryId)
            ->whereIn('status', [PayoutAllocationStatus::Reserved->value, PayoutAllocationStatus::Consumed->value])
            ->sum('allocated_amount_minor');

        return max(0, $entry->net_amount_minor - $allocatedMinor);
    }
}
