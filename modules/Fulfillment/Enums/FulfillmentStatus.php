<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Enums;

enum FulfillmentStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case ALLOCATED = 'allocated';
    case PICKING = 'picking';
    case PACKING = 'packing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
