<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class MarketplaceCommercialPolicyException extends MarketplaceException
{
    public static function missingPolicy(): self
    {
        return new self('Marketplace commercial model is not configured for the store or tenant context. Fail closed.');
    }
}
