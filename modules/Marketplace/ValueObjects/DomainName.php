<?php

declare(strict_types=1);

namespace Modules\Marketplace\ValueObjects;

use Modules\Marketplace\Exceptions\InvalidDomainNameException;

final readonly class DomainName
{
    private string $value;

    public function __construct(string $rawDomain)
    {
        $normalized = strtolower(trim($rawDomain));

        // Reject schemes (e.g. http://, https://)
        if (str_contains($normalized, '://')) {
            throw InvalidDomainNameException::containsScheme($rawDomain);
        }

        // Reject paths
        if (str_contains($normalized, '/')) {
            throw InvalidDomainNameException::containsPath($rawDomain);
        }

        // Reject ports
        if (str_contains($normalized, ':')) {
            throw InvalidDomainNameException::containsPort($rawDomain);
        }

        // Strip trailing dot
        $normalized = rtrim($normalized, '.');

        // Punycode/IDN conversion
        if (function_exists('idn_to_ascii')) {
            $converted = idn_to_ascii($normalized, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($converted !== false) {
                $normalized = $converted;
            }
        }

        if (strlen($normalized) < 3 || strlen($normalized) > 255) {
            throw InvalidDomainNameException::invalidLength($rawDomain);
        }

        // Reject IP addresses
        if (filter_var($normalized, FILTER_VALIDATE_IP)) {
            throw InvalidDomainNameException::ipAddressNotAllowed($rawDomain);
        }

        // Validate domain format
        if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.[a-z0-9-]{1,63})+$/', $normalized)) {
            throw InvalidDomainNameException::invalidFormat($rawDomain);
        }

        $this->value = $normalized;
    }

    public static function from(string $rawDomain): self
    {
        return new self($rawDomain);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
