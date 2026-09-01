<?php

declare(strict_types=1);

namespace Modules\Checkout\Exceptions;

use RuntimeException;

class ShippingQuoteStaleException extends RuntimeException
{
    public function __construct(string $message = 'SHIPPING_QUOTE_STALE: Selected shipping quote is no longer valid due to checkout state changes. Re-selection is required.', int $code = 409)
    {
        parent::__construct($message, $code);
    }
}
