<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorPlanSubscriptionException extends MarketplaceException
{
    public static function fakeSubscriptionForbiddenInProduction(): self
    {
        return new self("Activation source 'test_fake' is strictly prohibited in production and staging environments.");
    }

    public static function autoApprovalDeniedUnpaid(): self
    {
        return new self('Automatic vendor approval is denied: paid plan requires an active subscription entitlement with verified provenance.');
    }

    public static function autoApprovalDeniedFreePlan(): self
    {
        return new self('Automatic vendor approval is denied: free plans require manual administrator approval.');
    }
}
