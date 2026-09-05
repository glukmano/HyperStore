<?php

declare(strict_types=1);

namespace Modules\B2B\Enums;

/**
 * Owner Delta §1 (economic correction): pure append-only delta model.
 * `Reservation` increases exposure; `Release`/`Settlement` resolve a
 * reservation back to zero (never both — DB-enforced by a partial unique
 * index on `reverses_entry_id`). A refund AFTER settlement never posts an
 * entry here at all — it is handled entirely by ordinary Payment/accounting
 * semantics, never by this subledger.
 */
enum CompanyCreditEntryType: string
{
    case Reservation = 'reservation';
    case Release = 'release';
    case Settlement = 'settlement';
    case ManualAdjustmentCredit = 'manual_adjustment_credit';
    case ManualAdjustmentDebit = 'manual_adjustment_debit';

    public function isResolution(): bool
    {
        return $this === self::Release || $this === self::Settlement;
    }
}
