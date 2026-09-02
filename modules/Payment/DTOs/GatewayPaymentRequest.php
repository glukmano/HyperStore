<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

final readonly class GatewayPaymentRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $tenantId,
        public int $paymentId,
        public int $transactionId,
        public int $amountMinor,
        public string $currency,
        public ?string $paymentMethodType,
        public ?string $paymentMethodReference,
        public string $providerIdempotencyKey,
        public array $metadata = []
    ) {}
}
