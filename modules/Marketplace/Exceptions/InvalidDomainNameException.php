<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class InvalidDomainNameException extends MarketplaceException
{
    public static function containsScheme(string $domain): self
    {
        return new self("Domain '{$domain}' must not include a protocol/scheme (e.g., http://, https://).");
    }

    public static function containsPath(string $domain): self
    {
        return new self("Domain '{$domain}' must not include URL paths.");
    }

    public static function containsPort(string $domain): self
    {
        return new self("Domain '{$domain}' must not include port numbers.");
    }

    public static function invalidLength(string $domain): self
    {
        return new self("Domain '{$domain}' length is invalid.");
    }

    public static function ipAddressNotAllowed(string $domain): self
    {
        return new self("Domain '{$domain}' must be a fully qualified hostname, not an IP address.");
    }

    public static function invalidFormat(string $domain): self
    {
        return new self("Domain '{$domain}' is not a valid hostname format.");
    }
}
