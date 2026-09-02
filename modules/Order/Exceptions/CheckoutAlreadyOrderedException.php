<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use RuntimeException;

class CheckoutAlreadyOrderedException extends RuntimeException
{
    public static function forCheckout(int $checkoutId): self
    {
        return new self("An order has already been created for checkout [{$checkoutId}].", 409);
    }
}
