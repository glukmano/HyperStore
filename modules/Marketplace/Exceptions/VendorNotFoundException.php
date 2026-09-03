<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorNotFoundException extends MarketplaceException
{
    public static function forUuid(string $uuid): self
    {
        return new self("Vendor with UUID '{$uuid}' was not found.");
    }

    public static function forSlug(string $slug): self
    {
        return new self("Vendor with platform slug '{$slug}' was not found.");
    }
}
