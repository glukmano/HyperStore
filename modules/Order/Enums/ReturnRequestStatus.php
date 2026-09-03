<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum ReturnRequestStatus: string
{
    case REQUESTED = 'requested';
    case PARTIALLY_APPROVED = 'partially_approved';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
