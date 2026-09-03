<?php

declare(strict_types=1);

namespace Modules\Ledger\Enums;

enum SystemAccountRole: string
{
    case PAYMENT_CLEARING = 'payment_clearing';
    case CUSTOMER_FUNDS_LIABILITY = 'customer_funds_liability';
}
