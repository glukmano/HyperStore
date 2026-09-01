<?php

declare(strict_types=1);

namespace Modules\Checkout\Exceptions;

use Exception;

class CheckoutAccessDeniedException extends Exception
{
    public static function forCheckout(int $checkoutId): self
    {
        return new self("Access denied to CheckoutSession [{$checkoutId}]. Ownership verification failed.", 403);
    }
}
