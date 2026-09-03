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
use Modules\Ledger\Exceptions\LedgerAccountInvariantException;
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
        /** @var LedgerAccount|null $existing */
        $existing = LedgerAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('role', $role)
            ->first();

        if ($existing !== null) {
            // Validate that existing required role has compatible invariants
            if (! $existing->is_system) {
                throw LedgerAccountInvariantException::incompatibleExistingSystemAccount($role, 'is_system is false');
            }
            if ($existing->type !== $type) {
                throw LedgerAccountInvariantException::incompatibleExistingSystemAccount($role, "expected type [{$type}], found [{$existing->type}]");
            }
            if ($existing->normal_balance !== $normalBalance) {
                throw LedgerAccountInvariantException::incompatibleExistingSystemAccount($role, "expected normal balance [{$normalBalance}], found [{$existing->normal_balance}]");
            }
            if ($existing->status !== AccountStatus::ACTIVE->value) {
                throw LedgerAccountInvariantException::incompatibleExistingSystemAccount($role, "account is not active (status: {$existing->status})");
            }

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
            // Concurrent race condition: if another worker created the account concurrently, check existence and invariants
            /** @var LedgerAccount|null $raced */
            $raced = LedgerAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('role', $role)
                ->first();

            if ($raced === null) {
                throw $e;
            }

            if (! $raced->is_system || $raced->type !== $type || $raced->normal_balance !== $normalBalance || $raced->status !== AccountStatus::ACTIVE->value) {
                throw LedgerAccountInvariantException::incompatibleExistingSystemAccount($role, 'concurrently created account violates invariants');
            }
        }
    }
}
