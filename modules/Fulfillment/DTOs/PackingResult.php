<?php

declare(strict_types=1);

namespace Modules\Fulfillment\DTOs;

use Modules\Shipping\ValueObjects\PackageCandidate;

final readonly class PackingResult
{
    /**
     * @param  array<int, PackageCandidate>  $packages
     */
    public function __construct(
        public bool $isSuccessful,
        public array $packages = [],
        public ?PackingFailure $failure = null
    ) {}

    /**
     * @param  array<int, PackageCandidate>  $packages
     */
    public static function success(array $packages): self
    {
        return new self(isSuccessful: true, packages: $packages);
    }

    public static function failed(PackingFailure $failure): self
    {
        return new self(isSuccessful: false, packages: [], failure: $failure);
    }
}
