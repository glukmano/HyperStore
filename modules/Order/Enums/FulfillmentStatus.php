<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum FulfillmentStatus: string
{
    case UNFULFILLED = 'unfulfilled';
    case PARTIALLY_FULFILLED = 'partially_fulfilled';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';
}
