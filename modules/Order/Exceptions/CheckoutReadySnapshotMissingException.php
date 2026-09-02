<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use DomainException;

class CheckoutReadySnapshotMissingException extends DomainException
{
    public static function forCheckout(int $checkoutId): self
    {
        return new self("CHECKOUT_READY_SNAPSHOT_MISSING: Checkout session [{$checkoutId}] has state ready_for_order but lacks an immutable ready_snapshot handoff payload.");
    }

    public static function malformed(int $checkoutId, string $reason): self
    {
        return new self("CHECKOUT_READY_SNAPSHOT_MALFORMED: Checkout session [{$checkoutId}] ready_snapshot is invalid or incomplete: {$reason}");
    }
}
