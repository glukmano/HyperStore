<?php

declare(strict_types=1);

namespace Modules\Affiliate\Exceptions;

final class AffiliateOperationalStatusException extends AffiliateException
{
    public static function affiliateNotActive(string $affiliateUuid, string $currentStatus): self
    {
        return new self("Affiliate '{$affiliateUuid}' is not active (current status: '{$currentStatus}').");
    }
}
