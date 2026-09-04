<?php

declare(strict_types=1);

namespace Modules\Marketplace\ValueObjects;

use App\Core\Support\ReservedSlugs;
use Modules\Marketplace\Exceptions\InvalidVendorSlugException;

final readonly class VendorSlug
{
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

        if (in_array($normalized, ReservedSlugs::LIST, true)) {
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
