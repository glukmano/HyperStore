<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use RuntimeException;

class CheckoutNotReadyException extends RuntimeException
{
    public static function invalidState(int $checkoutId, string $actualState): self
    {
        return new self("Checkout [{$checkoutId}] is not in ready_for_order state (actual state: [{$actualState}]).", 422);
    }

    public static function forState(int $checkoutId, string $actualState): self
    {
        return self::invalidState($checkoutId, $actualState);
    }
}
