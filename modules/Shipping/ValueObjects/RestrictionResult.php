<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

final readonly class RestrictionResult
{
    public function __construct(
        public bool $isRestricted,
        public ?string $reason = null,
        public ?string $restrictionType = null
    ) {}

    public static function allowed(): self
    {
        return new self(isRestricted: false);
    }

    public static function restricted(string $reason, ?string $restrictionType = null): self
    {
        return new self(isRestricted: true, reason: $reason, restrictionType: $restrictionType);
    }
}
