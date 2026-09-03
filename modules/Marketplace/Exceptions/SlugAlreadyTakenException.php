<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class SlugAlreadyTakenException extends MarketplaceException
{
    public static function forSlug(string $slug): self
    {
        return new self("Platform vendor slug '{$slug}' is already registered.");
    }
}
