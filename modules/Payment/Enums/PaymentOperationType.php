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
}
