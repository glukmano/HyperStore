<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class MissingVendorPayableAvailabilityPolicyException extends MarketplaceException
{
    public static function forScope(int $tenantId, ?int $storeId = null): self
    {
        $target = $storeId !== null ? "tenant [{$tenantId}] and store [{$storeId}]" : "tenant [{$tenantId}]";

        return new self("No authoritative payable hold policy ('marketplace.payable_hold_days') configured for {$target}.");
    }
}
