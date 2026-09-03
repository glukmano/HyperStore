<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Enums;

enum ShipmentStatus: string
{
    case MANIFESTED = 'manifested';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERED = 'delivered';
    case FAILED_DELIVERY = 'failed_delivery';
    case RETURNED_TO_SENDER = 'returned_to_sender';
    case CANCELLED = 'cancelled';
}
