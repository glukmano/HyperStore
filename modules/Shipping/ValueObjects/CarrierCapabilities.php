<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

final readonly class CarrierCapabilities
{
    public function __construct(
        public bool $supportsRating = true,
        public bool $supportsLabels = false,
        public bool $supportsTracking = false,
        public bool $supportsPickup = false,
        public int $connectTimeoutSeconds = 5,
        public int $requestTimeoutSeconds = 10,
        public int $maxRetries = 2
    ) {}
}
