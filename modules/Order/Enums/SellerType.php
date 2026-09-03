<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum SellerType: string
{
    case PLATFORM = 'platform';
    case VENDOR = 'vendor';
}
