<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case AUTHORIZED = 'authorized';
    case PAID = 'paid';
    case REFUNDED = 'refunded';
    case VOIDED = 'voided';
}
