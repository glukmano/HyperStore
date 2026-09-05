<?php

declare(strict_types=1);

namespace App\Core\Payables\Enums;

/**
 * Beneficiary-agnostic payout-request lifecycle. Shared by every payable
 * beneficiary type (Vendor, Affiliate, ...) via AbstractPayoutOrchestrator —
 * moved out of Modules\Marketplace because nothing about this state machine
 * is Vendor-specific.
 */
enum PayoutRequestStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Cancelled], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Requested, self::Approved], true);
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        if ($this->isTerminal()) {
            return false;
        }

        return match ($this) {
            self::Requested => in_array($target, [self::Approved, self::Cancelled, self::Failed], true),
            self::Approved => in_array($target, [self::Processing, self::Cancelled, self::Failed], true),
            self::Processing => in_array($target, [self::Paid, self::Failed], true),
            default => false,
        };
    }
}
