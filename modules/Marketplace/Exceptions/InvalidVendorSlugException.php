<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class InvalidVendorSlugException extends MarketplaceException
{
    public static function invalidLength(string $slug): self
    {
        return new self("Vendor slug '{$slug}' must be between 3 and 64 characters.");
    }

    public static function invalidFormat(string $slug): self
    {
        return new self("Vendor slug '{$slug}' must contain only lowercase alphanumeric characters and single hyphens.");
    }

    public static function reservedSlug(string $slug): self
    {
        return new self("Vendor slug '{$slug}' is a reserved platform keyword and cannot be used.");
    }
}
