<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum StatusDimension: string
{
    case ORDER = 'order';
    case PAYMENT = 'payment';
    case FULFILLMENT = 'fulfillment';
}
