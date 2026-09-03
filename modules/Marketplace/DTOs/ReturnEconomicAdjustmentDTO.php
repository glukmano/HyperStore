<?php

declare(strict_types=1);

namespace Modules\Marketplace\DTOs;

final readonly class ReturnEconomicAdjustmentDTO
{
    public function __construct(
        public int $tenantId,
        public int $vendorId,
        public string $sourceUuid,
        public string $currency,
        public int $amountMinor,
        public int $commissionReversalMinor,
        public int $netDebitMinor,
        public string $reason
    ) {}
}
