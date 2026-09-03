<?php

declare(strict_types=1);

namespace Modules\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Contracts\AccountBalanceQueryInterface;
use Modules\Ledger\DTOs\AccountBalanceDTO;
use Modules\Ledger\Enums\NormalBalance;
use Modules\Ledger\Exceptions\CrossTenantAccessException;
use Modules\Ledger\Models\LedgerAccount;

class AccountBalanceQueryService implements AccountBalanceQueryInterface
{
    /**
     * @return list<AccountBalanceDTO>
     */
    public function getBalances(int $tenantId, int $accountId): array
    {
        /** @var LedgerAccount|null $account */
        $account = LedgerAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $accountId)
            ->first();

        if ($account === null) {
            throw CrossTenantAccessException::forAccount($tenantId, $accountId);
        }

        $rows = DB::table('journal_lines')
            ->select('currency')
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount_minor ELSE 0 END) as debit_total")
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE 0 END) as credit_total")
            ->where('tenant_id', $tenantId)
            ->where('ledger_account_id', $accountId)
            ->groupBy('currency')
            ->get();

        $dtos = [];

        foreach ($rows as $row) {
            $debitTotal = (int) $row->debit_total;
            $creditTotal = (int) $row->credit_total;

            $balance = $account->normal_balance === NormalBalance::DEBIT->value
                ? ($debitTotal - $creditTotal)
                : ($creditTotal - $debitTotal);

            $dtos[] = new AccountBalanceDTO(
                accountId: $accountId,
                currency: (string) $row->currency,
                debitTotalMinor: $debitTotal,
                creditTotalMinor: $creditTotal,
                balanceMinor: $balance
            );
        }

        return $dtos;
    }

    public function getBalanceForCurrency(int $tenantId, int $accountId, string $currency): AccountBalanceDTO
    {
        /** @var LedgerAccount|null $account */
        $account = LedgerAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $accountId)
            ->first();

        if ($account === null) {
            throw CrossTenantAccessException::forAccount($tenantId, $accountId);
        }

        $row = DB::table('journal_lines')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_minor ELSE 0 END), 0) as debit_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE 0 END), 0) as credit_total")
            ->where('tenant_id', $tenantId)
            ->where('ledger_account_id', $accountId)
            ->where('currency', $currency)
            ->first();

        $debitTotal = (int) ($row->debit_total ?? 0);
        $creditTotal = (int) ($row->credit_total ?? 0);

        $balance = $account->normal_balance === NormalBalance::DEBIT->value
            ? ($debitTotal - $creditTotal)
            : ($creditTotal - $debitTotal);

        return new AccountBalanceDTO(
            accountId: $accountId,
            currency: $currency,
            debitTotalMinor: $debitTotal,
            creditTotalMinor: $creditTotal,
            balanceMinor: $balance
        );
    }
}
