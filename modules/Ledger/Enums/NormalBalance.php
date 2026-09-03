<?php

declare(strict_types=1);

namespace Modules\Ledger\Enums;

enum NormalBalance: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';
}
