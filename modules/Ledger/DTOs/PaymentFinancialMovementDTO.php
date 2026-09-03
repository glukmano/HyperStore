<?php

declare(strict_types=1);

namespace Modules\Ledger\DTOs;

use Carbon\CarbonImmutable;

final readonly class PaymentFinancialMovementDTO
{
    public function __construct(
        public int $tenantId,
        public string $paymentUuid,
        public string $transactionUuid,
        public string $operationType,
        public int $amountMinor,
        public string $currency,
        public CarbonImmutable $occurredAtUtc,
        public ?string $orderUuid = null
    ) {}
}
