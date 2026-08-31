<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

final readonly class ProviderError
{
    public function __construct(
        public string $carrierCode,
        public string $errorCode, // timeout, auth_error, rate_unavailable, network_error
        public string $message,
        public bool $isRetryable = false
    ) {}
}
