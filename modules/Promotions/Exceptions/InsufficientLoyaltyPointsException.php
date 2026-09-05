<?php

declare(strict_types=1);

namespace Modules\Promotions\Exceptions;

final class InsufficientLoyaltyPointsException extends \RuntimeException
{
    public static function forRequest(int $requested, int $available): self
    {
        return new self("Requested redemption of {$requested} points exceeds available balance of {$available} points.");
    }
}
