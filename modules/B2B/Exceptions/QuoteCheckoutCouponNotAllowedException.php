<?php

declare(strict_types=1);

namespace Modules\B2B\Exceptions;

/**
 * Owner Delta §4/§20: an accepted-Quote checkout does not stack additional
 * marketing or Loyalty coupon discount on top of an already-negotiated
 * price, by default.
 */
final class QuoteCheckoutCouponNotAllowedException extends B2BException
{
    public static function forCart(int $cartId): self
    {
        return new self("Cart [{$cartId}] contains negotiated Quote pricing and does not accept coupon codes.");
    }
}
