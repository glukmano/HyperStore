<?php

declare(strict_types=1);

namespace Modules\Order\Enums;

enum FulfillmentStatus: string
{
    case UNFULFILLED = 'unfulfilled';
    case PARTIALLY_FULFILLED = 'partially_fulfilled';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    /**
     * Phase-22 BOPIS lifecycle (Owner Delta §7): reserved -> preparing ->
     * READY_FOR_PICKUP -> PICKED_UP. UNFULFILLED/PARTIALLY_FULFILLED cover
     * "reserved"/"preparing" for a pickup Order — these two are the new,
     * pickup-specific terminal-adjacent states.
     */
    case READY_FOR_PICKUP = 'ready_for_pickup';
    case PICKED_UP = 'picked_up';
}
