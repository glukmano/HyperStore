<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum SellerReturnStatus: string
{
    case REQUESTED = 'requested';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case RECEIVED = 'received';
    case INSPECTED = 'inspected';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
