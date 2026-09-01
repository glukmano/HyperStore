<?php

declare(strict_types=1);

namespace Modules\Checkout\Exceptions;

use RuntimeException;

class CheckoutExpiredException extends RuntimeException
{
    public function __construct(string $message = 'CHECKOUT_EXPIRED: Checkout session has expired.', int $code = 410)
    {
        parent::__construct($message, $code);
    }
}
