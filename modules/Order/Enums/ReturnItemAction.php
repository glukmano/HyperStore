<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum ReturnItemAction: string
{
    case REFUND = 'refund';
    case REPLACEMENT = 'replacement';
    case REPAIR = 'repair';
}
