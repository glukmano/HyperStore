<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Enums;

enum PurchaseOrderStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case CONFIRMED = 'confirmed';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';
}
