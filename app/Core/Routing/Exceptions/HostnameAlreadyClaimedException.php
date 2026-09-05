<?php

declare(strict_types=1);

namespace App\Core\Routing\Exceptions;

use RuntimeException;

final class HostnameAlreadyClaimedException extends RuntimeException
{
    public static function forHost(string $normalizedHostname): self
    {
        return new self("The hostname [{$normalizedHostname}] is already claimed by another Store/Market/Vendor.");
    }
}
