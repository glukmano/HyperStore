<?php

declare(strict_types=1);

namespace Modules\B2B\Exceptions;

final class InsufficientCompanyCreditException extends B2BException
{
    public static function forRequest(int $requestedMinor, int $availableMinor, string $currency): self
    {
        return new self("Order amount [{$requestedMinor} {$currency}] exceeds available Company credit [{$availableMinor} {$currency}].");
    }
}
