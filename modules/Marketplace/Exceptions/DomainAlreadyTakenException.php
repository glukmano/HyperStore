<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class DomainAlreadyTakenException extends MarketplaceException
{
    public static function forDomain(string $domain): self
    {
        return new self("Custom domain '{$domain}' is already claimed by another vendor.");
    }
}
