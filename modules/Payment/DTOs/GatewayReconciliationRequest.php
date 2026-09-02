<?php

declare(strict_types=1);

namespace Modules\Payment\DTOs;

final readonly class GatewayReconciliationRequest
{
    public function __construct(
        public int $tenantId,
        public ?string $providerReference,
        public ?string $providerIdempotencyKey,
        public string $operationType,
        public int $expectedAmountMinor,
        public string $expectedCurrency
    ) {}
}
