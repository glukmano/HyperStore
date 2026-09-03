<?php

declare(strict_types=1);

namespace Modules\Ledger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Enums\AccountStatus;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\Enums\NormalBalance;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Exceptions\MissingSystemAccountException;
use Modules\Ledger\Models\LedgerAccount;

class LedgerAccountRegistry implements LedgerAccountRegistryInterface
{
    public function ensureRequiredSystemAccounts(int $tenantId): void
    {
        $this->ensureAccount(
            tenantId: $tenantId,
            role: SystemAccountRole::PAYMENT_CLEARING->value,
            code: 'payment_clearing',
            name: 'Payment Gateway Clearing',
            type: AccountType::ASSET->value,
            normalBalance: NormalBalance::DEBIT->value,
            description: 'Clearing account for customer funds captured via payment gateways'
        );

        $this->ensureAccount(
            tenantId: $tenantId,
            role: SystemAccountRole::CUSTOMER_FUNDS_LIABILITY->value,
            code: 'customer_funds_liability',
            name: 'Customer Funds Liability',
            type: AccountType::LIABILITY->value,
            normalBalance: NormalBalance::CREDIT->value,
            description: 'Unallocated customer funds liability prior to commercial revenue recognition'
        );
    }

    public function getAccountByRole(int $tenantId, SystemAccountRole|string $role): LedgerAccount
    {
        $roleValue = $role instanceof SystemAccountRole ? $role->value : $role;

        /** @var LedgerAccount|null $account */
        $account = LedgerAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('role', $roleValue)
            ->where('status', AccountStatus::ACTIVE->value)
            ->first();

        if ($account === null) {
            throw MissingSystemAccountException::forRole($tenantId, $roleValue);
        }

        return $account;
    }

    private function ensureAccount(
        int $tenantId,
        string $role,
        string $code,
        string $name,
        string $type,
        string $normalBalance,
        string $description
    ): void {
        $existing = LedgerAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('role', $role)
            ->first();

        if ($existing !== null) {
            return;
        }

        $now = CarbonImmutable::now('UTC');

        try {
            DB::transaction(function () use ($tenantId, $role, $code, $name, $type, $normalBalance, $description, $now): void {
                LedgerAccount::withoutGlobalScopes()->create([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'normal_balance' => $normalBalance,
                    'role' => $role,
                    'currency' => null,
                    'is_system' => true,
                    'status' => AccountStatus::ACTIVE->value,
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (QueryException $e) {
            // Concurrent race condition: if another worker created the account concurrently, check existence
            $exists = LedgerAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('role', $role)
                ->exists();

            if (! $exists) {
                throw $e;
            }
        }
    }
}
