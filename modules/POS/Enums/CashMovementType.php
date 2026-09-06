<?php

declare(strict_types=1);

namespace Modules\POS\Enums;

/**
 * Owner Delta §3: closing_count is deliberately NOT a movement type — a
 * physical count is not cash entering/leaving the drawer. Expected cash is
 * derived ONLY from these five real movement types.
 */
enum CashMovementType: string
{
    case OPENING_FLOAT = 'opening_float';
    case SALE_CASH_IN = 'sale_cash_in';
    case REFUND_CASH_OUT = 'refund_cash_out';
    case PAID_IN = 'paid_in';
    case PAID_OUT = 'paid_out';

    public function isInflow(): bool
    {
        return in_array($this, [self::OPENING_FLOAT, self::SALE_CASH_IN, self::PAID_IN], true);
    }
}
