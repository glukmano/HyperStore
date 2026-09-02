<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

final readonly class GatewayVoidRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $tenantId,
        public int $paymentId,
        public int $transactionId,
        public ?string $providerReference,
        public string $providerIdempotencyKey,
        public array $metadata = []
    ) {}
}
