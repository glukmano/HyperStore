<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum ReservationOwnerType: string
{
    case CHECKOUT = 'checkout';
    case ORDER = 'order';
}
