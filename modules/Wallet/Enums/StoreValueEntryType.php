<?php

declare(strict_types=1);

namespace Modules\Wallet\Enums;

/**
 * Owner Delta §16: hold/release are a pre-commercial reservation pair that
 * nets to zero if released and never post to Ledger. Only issue/capture/
 * refund_credit/expire/manual_adjustment_* are final economic events.
 */
enum StoreValueEntryType: string
{
    case Issue = 'issue';
    case Hold = 'hold';
    case Capture = 'capture';
    case Release = 'release';
    case RefundCredit = 'refund_credit';
    case Expire = 'expire';
    case ManualAdjustmentCredit = 'manual_adjustment_credit';
    case ManualAdjustmentDebit = 'manual_adjustment_debit';
}
