<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use DomainException;

class InvalidOrderTransitionException extends DomainException
{
    public static function forTransition(string $from, string $to, string $dimension = 'order'): self
    {
        return new self("INVALID_ORDER_TRANSITION: Cannot transition {$dimension} from [{$from}] to [{$to}].");
    }

    public static function staleTransition(string $expectedStatus, string $actualStatus): self
    {
        return new self("STALE_ORDER_TRANSITION: Expected current order status [{$expectedStatus}], but actual status is [{$actualStatus}].");
    }

    public static function unsupportedDimension(string $dimension): self
    {
        return new self("UNSUPPORTED_STATUS_DIMENSION: Transitions along dimension [{$dimension}] are not permitted in Phase-08. They are owned by dedicated lifecycle modules (Phase-09+).");
    }
}
