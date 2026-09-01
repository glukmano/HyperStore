<?php

declare(strict_types=1);

namespace Modules\Checkout\Exceptions;

use RuntimeException;

class PriceUnavailableException extends RuntimeException
{
    public static function forProduct(int $productId, ?int $variantId = null): self
    {
        $varStr = $variantId !== null ? " variant [{$variantId}]" : '';

        return new self("PRICE_UNAVAILABLE: Price could not be authoritatively resolved for product [{$productId}]{$varStr}.", 422);
    }
}
