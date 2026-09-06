<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

/**
 * Owner Delta §9: a Subscription created against a gateway not
 * implementing RecurringPaymentGatewayInterface fails deterministically at
 * setup/renewal time with a clear, actionable error — never silently
 * attempted and failed later.
 */
class GatewayDoesNotSupportRecurringException extends RuntimeException
{
    public static function forProvider(string $providerCode): self
    {
        return new self("Payment provider [{$providerCode}] does not support recurring billing.");
    }
}
