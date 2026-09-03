<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum RefundEligibilityStatus: string
{
    case PENDING = 'pending';
    case ELIGIBLE = 'eligible';
    case REFUNDED = 'refunded';
    case DENIED = 'denied';
}
