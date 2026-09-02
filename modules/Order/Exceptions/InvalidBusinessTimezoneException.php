<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use RuntimeException;

class InvalidBusinessTimezoneException extends RuntimeException
{
    public static function unresolvable(int $marketId, int $storeId): self
    {
        return new self("Unable to resolve a valid business timezone for market [{$marketId}] and store [{$storeId}].", 500);
    }

    public static function invalid(string $timezone): self
    {
        return new self("The resolved business timezone [{$timezone}] is invalid.", 500);
    }
}
