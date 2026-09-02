<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

final readonly class InitiatePaymentDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $tenantId,
        public int $orderId,
        public int $amountMinor,
        public string $currency,
        public ?string $providerCode = null,
        public ?string $paymentMethodType = null,
        public ?string $paymentMethodReference = null,
        public bool $captureImmediately = true,
        public ?string $idempotencyKey = null,
        public array $metadata = []
    ) {}
}
