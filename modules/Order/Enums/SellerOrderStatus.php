<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum SellerOrderStatus: string
{
    case OPEN = 'open';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
