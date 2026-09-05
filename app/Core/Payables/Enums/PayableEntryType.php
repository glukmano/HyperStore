<?php

declare(strict_types=1);

namespace App\Core\Payables\Enums;

/**
 * Beneficiary-agnostic payable-subledger entry type. Renamed from
 * Modules\Marketplace\Enums\VendorPayableEntryType on generalization — the
 * "Vendor" prefix no longer applied once Affiliate payables adopted the
 * identical entry-type vocabulary.
 */
enum PayableEntryType: string
{
    case Earning = 'earning';
    case ManualAdjustmentCredit = 'manual_adjustment_credit';
    case RefundAdjustment = 'refund_adjustment';
    case ManualAdjustmentDebit = 'manual_adjustment_debit';
    case PayoutDisbursement = 'payout_disbursement';

    public function isCredit(): bool
    {
        return in_array($this, [self::Earning, self::ManualAdjustmentCredit], true);
    }

    public function isDebit(): bool
    {
        return in_array($this, [self::RefundAdjustment, self::ManualAdjustmentDebit, self::PayoutDisbursement], true);
    }

    public function polarityMultiplier(): int
    {
        return $this->isCredit() ? 1 : -1;
    }

    public function isAllocatableSource(): bool
    {
        return in_array($this, [self::Earning, self::ManualAdjustmentCredit], true);
    }
}
