<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

final readonly class ProviderError
{
    public function __construct(
        public string $carrierCode,
        public string $providerCode,
        public string $errorCode, // authentication_error, timeout, unavailable_service, invalid_address, rate_not_available, provider_internal_error, network_error
        public string $safeMessage,
        public bool $isRetryable = false,
        public ?string $correlationId = null,
        public ?int $latencyMs = null
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'carrier_code' => $this->carrierCode,
            'provider_code' => $this->providerCode,
            'error_code' => $this->errorCode,
            'safe_message' => $this->safeMessage,
            'is_retryable' => $this->isRetryable,
            'correlation_id' => $this->correlationId,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
