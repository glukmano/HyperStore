<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\Enums\AccountStatus;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Enums\NormalBalance;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Exceptions\AccountCurrencyMismatchException;
use Modules\Ledger\Exceptions\LedgerAccountInvariantException;
use Modules\Ledger\Models\LedgerAccount;
use Tests\TestCase;

class LedgerAccountClassificationSafetyTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
    }

    public function test_cannot_mutate_role_on_system_account(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);

        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Cannot mutate system account field [role]');

        $clearing->role = 'custom_role';
        $clearing->save();
    }

    public function test_cannot_mutate_type_on_system_account(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);

        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Cannot mutate system account field [type]');

        $clearing->type = AccountType::LIABILITY->value;
        $clearing->save();
    }

    public function test_cannot_mutate_normal_balance_on_system_account(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);

        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Cannot mutate system account field [normal_balance]');

        $clearing->normal_balance = NormalBalance::CREDIT->value;
        $clearing->save();
    }

    public function test_cannot_mutate_tenant_on_system_account(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);

        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Cannot mutate system account field [tenant_id]');

        $clearing->tenant_id = 99999;
        $clearing->save();
    }

    public function test_cannot_archive_or_deactivate_required_system_account(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);

        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Cannot archive or deactivate required system account with role [payment_clearing]');

        $clearing->status = AccountStatus::ARCHIVED->value;
        $clearing->save();
    }

    public function test_cannot_mutate_currency_after_journal_lines_exist(): void
    {
        $restrictedAccount = LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'usd_bank',
            'name' => 'USD Bank Account',
            'type' => AccountType::ASSET->value,
            'normal_balance' => NormalBalance::DEBIT->value,
            'currency' => 'USD',
            'is_system' => false,
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $liability = LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'usd_liability',
            'name' => 'USD Liability',
            'type' => AccountType::LIABILITY->value,
            'normal_balance' => NormalBalance::CREDIT->value,
            'currency' => 'USD',
            'is_system' => false,
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var LedgerPostingServiceInterface $postingService */
        $postingService = app(LedgerPostingServiceInterface::class);
        $now = CarbonImmutable::now('UTC');

        $postingService->post(new JournalDraftDTO(
            tenantId: $this->tenant->id,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'test-posted-curr',
            postingType: 'capture',
            currency: 'USD',
            description: 'Test currency lock',
            effectiveAt: $now,
            postedAt: $now,
            lines: [
                new JournalLineDTO((int) $restrictedAccount->id, JournalDirection::DEBIT, 1000, 'USD'),
                new JournalLineDTO((int) $liability->id, JournalDirection::CREDIT, 1000, 'USD'),
            ]
        ));

        // Now attempt to change currency of the account that has lines
        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Cannot mutate field [currency] on account with existing journal lines');

        $restrictedAccount->currency = 'EUR';
        $restrictedAccount->save();
    }

    public function test_account_currency_restriction_rejects_mismatch_at_posting(): void
    {
        $restrictedAccount = LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'usd_bank_2',
            'name' => 'USD Bank Account',
            'type' => AccountType::ASSET->value,
            'normal_balance' => NormalBalance::DEBIT->value,
            'currency' => 'USD',
            'is_system' => false,
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $multiCurrencyAccount = LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'multi_clearing_2',
            'name' => 'Multi Clearing',
            'type' => AccountType::LIABILITY->value,
            'normal_balance' => NormalBalance::CREDIT->value,
            'currency' => null,
            'is_system' => false,
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var LedgerPostingServiceInterface $postingService */
        $postingService = app(LedgerPostingServiceInterface::class);
        $now = CarbonImmutable::now('UTC');

        $this->expectException(AccountCurrencyMismatchException::class);
        $postingService->post(new JournalDraftDTO(
            tenantId: $this->tenant->id,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'test-curr-mismatch',
            postingType: 'capture',
            currency: 'EUR', // Posting EUR to USD account
            description: 'Test currency restriction',
            effectiveAt: $now,
            postedAt: $now,
            lines: [
                new JournalLineDTO((int) $restrictedAccount->id, JournalDirection::DEBIT, 1000, 'EUR'),
                new JournalLineDTO((int) $multiCurrencyAccount->id, JournalDirection::CREDIT, 1000, 'EUR'),
            ]
        ));
    }

    public function test_cosmetic_fields_can_be_updated(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);

        $clearing->name = 'Updated Display Name';
        $clearing->description = 'Updated Description Notes';
        $clearing->save();

        $fresh = $clearing->fresh();
        $this->assertSame('Updated Display Name', $fresh->name);
        $this->assertSame('Updated Description Notes', $fresh->description);
    }

    // --- Tests A through G for Final System-Account Lifecycle Invariants ---

    public function test_a_newly_provisioned_payment_clearing_with_zero_lines_cannot_be_deleted(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);
        $this->assertSame(0, $clearing->lines()->count(), 'Precondition: 0 journal lines must exist.');

        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Cannot delete system account with role [payment_clearing]');

        $clearing->delete();
    }

    public function test_b_newly_provisioned_customer_funds_liability_with_zero_lines_cannot_be_deleted(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);
        $this->assertSame(0, $liability->lines()->count(), 'Precondition: 0 journal lines must exist.');

        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Cannot delete system account with role [customer_funds_liability]');

        $liability->delete();
    }

    public function test_c_unused_ordinary_non_system_account_can_be_deleted(): void
    {
        $ordinaryAccount = LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'temporary_test_account',
            'name' => 'Temporary Test Account',
            'type' => AccountType::EXPENSE->value,
            'normal_balance' => NormalBalance::DEBIT->value,
            'currency' => 'EUR',
            'is_system' => false,
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, $ordinaryAccount->lines()->count());
        $accountId = $ordinaryAccount->id;

        $ordinaryAccount->delete();

        $this->assertNull(LedgerAccount::find($accountId), 'Unused non-system account must be deletable.');
    }

    public function test_d_attempt_creation_of_payment_clearing_with_is_system_false_is_rejected(): void
    {
        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Required system account with role [payment_clearing] must have is_system set to true');

        LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'fake_clearing',
            'name' => 'Fake Clearing',
            'type' => AccountType::ASSET->value,
            'normal_balance' => NormalBalance::DEBIT->value,
            'role' => SystemAccountRole::PAYMENT_CLEARING->value,
            'is_system' => false, // Violates invariant!
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_e_attempt_creation_of_customer_funds_liability_with_is_system_false_is_rejected(): void
    {
        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Required system account with role [customer_funds_liability] must have is_system set to true');

        LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'fake_liability',
            'name' => 'Fake Liability',
            'type' => AccountType::LIABILITY->value,
            'normal_balance' => NormalBalance::CREDIT->value,
            'role' => SystemAccountRole::CUSTOMER_FUNDS_LIABILITY->value,
            'is_system' => false, // Violates invariant!
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_f_provisioning_encounters_existing_incompatible_required_role_fails_closed(): void
    {
        // Manually create an existing account with invalid/corrupted classification bypassing model hook via DB::table
        DB::table('ledger_accounts')->insert([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'code' => 'corrupted_clearing',
            'name' => 'Corrupted Clearing',
            'type' => AccountType::EXPENSE->value, // Incompatible type for payment_clearing!
            'normal_balance' => NormalBalance::DEBIT->value,
            'role' => SystemAccountRole::PAYMENT_CLEARING->value,
            'is_system' => true,
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);

        $this->expectException(LedgerAccountInvariantException::class);
        $this->expectExceptionMessage('Existing required system account [payment_clearing] is incompatible');

        // Must FAIL CLOSED without silently rewriting or accepting
        $registry->ensureRequiredSystemAccounts($this->tenant->id);
    }

    public function test_g_valid_existing_required_role_provisioning_remains_idempotent(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);

        $registry->ensureRequiredSystemAccounts($this->tenant->id);
        $this->assertSame(2, LedgerAccount::where('tenant_id', $this->tenant->id)->count());

        // Repeated provisioning must succeed idempotently without modifying accounts
        $registry->ensureRequiredSystemAccounts($this->tenant->id);
        $this->assertSame(2, LedgerAccount::where('tenant_id', $this->tenant->id)->count());
    }
}
