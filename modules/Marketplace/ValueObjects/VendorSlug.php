<?php

declare(strict_types=1);

namespace Modules\Marketplace\ValueObjects;

use Modules\Marketplace\Exceptions\InvalidVendorSlugException;

final readonly class VendorSlug
{
    private const array RESERVED_SLUGS = [
        'admin',
        'api',
        'app',
        'assets',
        'auth',
        'billing',
        'cart',
        'checkout',
        'control',
        'dashboard',
        'docs',
        'help',
        'login',
        'logout',
        'mail',
        'marketplace',
        'orders',
        'payments',
        'platform',
        'portal',
        'root',
        'settings',
        'static',
        'status',
        'store',
        'stores',
        'support',
        'system',
        'vendor',
        'vendors',
        'webhook',
        'webhooks',
        'www',
    ];

    private string $value;

    public function __construct(string $rawSlug)
    {
        $normalized = strtolower(trim($rawSlug));

        if (strlen($normalized) < 3 || strlen($normalized) > 64) {
            throw InvalidVendorSlugException::invalidLength($rawSlug);
        }

        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $normalized)) {
            throw InvalidVendorSlugException::invalidFormat($rawSlug);
        }

        if (in_array($normalized, self::RESERVED_SLUGS, true)) {
            throw InvalidVendorSlugException::reservedSlug($rawSlug);
        }

        $this->value = $normalized;
    }

    public static function from(string $rawSlug): self
    {
        return new self($rawSlug);
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
