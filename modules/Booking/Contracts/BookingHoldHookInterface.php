<?php

declare(strict_types=1);

namespace Modules\Booking\Contracts;

use Modules\Checkout\Models\CheckoutSession;

/**
 * Owner Delta §5: the Booking hold is created at the exact same pipeline
 * step normal physical Inventory is reserved
 * (`CheckoutOrchestrator::reserveInventory()`), keyed by this Checkout
 * session's own uuid — never a separate, ad-hoc reservation moment.
 */
interface BookingHoldHookInterface
{
    public function createHoldsForCheckout(CheckoutSession $session): void;

    /**
     * Owner Delta §8 (correctness, not merely the scheduled sweep):
     * releases any still-held Booking immediately when its Checkout is
     * explicitly cancelled or expires, rather than waiting for the next
     * scheduled sweep.
     */
    public function cancelHoldsForCheckout(CheckoutSession $session): void;
}
