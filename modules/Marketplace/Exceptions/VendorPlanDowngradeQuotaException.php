<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorPlanDowngradeQuotaException extends MarketplaceException
{
    public static function listingLimitExceeded(int $currentListings, int $targetLimit): self
    {
        return new self("Cannot downgrade plan: current listings ({$currentListings}) exceed target plan limit ({$targetLimit}).");
    }

    public static function staffLimitExceeded(int $currentStaff, int $targetLimit): self
    {
        return new self("Cannot downgrade plan: current staff and pending invitations ({$currentStaff}) exceed target plan limit ({$targetLimit}).");
    }
}
