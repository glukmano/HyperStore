<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum OrderActorType: string
{
    case CUSTOMER = 'customer';
    case GUEST = 'guest';
    case STAFF = 'staff';
    case SYSTEM = 'system';
}
