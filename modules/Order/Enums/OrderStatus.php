<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum OrderStatus: string
{
    case PLACED = 'placed';
    case CONFIRMED = 'confirmed';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';
}
