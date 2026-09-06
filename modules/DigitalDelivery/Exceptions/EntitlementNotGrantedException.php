<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Exceptions;

class EntitlementNotGrantedException extends DigitalDeliveryException
{
    public static function forToken(): self
    {
        return new self('No valid, active entitlement grants access.');
    }
}
