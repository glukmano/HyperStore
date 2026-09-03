<?php

declare(strict_types=1);

namespace Modules\Ledger\DTOs;

final readonly class AccountBalanceDTO
{
    public function __construct(
        public int $accountId,
        public string $currency,
        public int $debitTotalMinor,
        public int $creditTotalMinor,
        public int $balanceMinor
    ) {}
}
