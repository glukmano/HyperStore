<?php

declare(strict_types=1);

namespace Modules\Payment\Enums;

enum PaymentOperationType: string
{
    case PURCHASE = 'purchase';
    case AUTHORIZE = 'authorize';
    case CAPTURE = 'capture';
    case VOID = 'void';
    case REFUND = 'refund';
    case ZERO_TOTAL_SETTLEMENT = 'zero_total_settlement';

    /**
     * Phase-22 / ADR-0155: a real, Ledger-integrated cash tender — no
     * gateway call, but a genuine captured payment, distinct from
     * ZERO_TOTAL_SETTLEMENT (which recognizes no money movement at all).
     */
    case CASH_SETTLEMENT = 'cash_settlement';
}
