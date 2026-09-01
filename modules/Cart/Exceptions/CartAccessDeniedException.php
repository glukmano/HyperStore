<?php

declare(strict_types=1);

namespace Modules\Cart\Exceptions;

use Exception;

class CartAccessDeniedException extends Exception
{
    public static function forCart(int $cartId): self
    {
        return new self("Access denied to Cart [{$cartId}]. Ownership verification failed.", 403);
    }
}
