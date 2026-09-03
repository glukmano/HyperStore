<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum VendorPayableEntryType: string
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
