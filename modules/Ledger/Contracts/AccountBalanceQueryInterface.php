<?php

declare(strict_types=1);

namespace Modules\Ledger\Contracts;

use Modules\Ledger\DTOs\AccountBalanceDTO;

interface AccountBalanceQueryInterface
{
    /**
     * @return list<AccountBalanceDTO>
     */
    public function getBalances(int $tenantId, int $accountId): array;

    public function getBalanceForCurrency(int $tenantId, int $accountId, string $currency): AccountBalanceDTO;
}
