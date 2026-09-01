<?php

declare(strict_types=1);

namespace Modules\Checkout\Exceptions;

use RuntimeException;

class ShippingQuoteExpiredException extends RuntimeException
{
    public static function forQuote(int $methodId): self
    {
        return new self("SHIPPING_QUOTE_EXPIRED: Selected shipping quote [{$methodId}] has expired. Re-quoting required.", 422);
    }
}
