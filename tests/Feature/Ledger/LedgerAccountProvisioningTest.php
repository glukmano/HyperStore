<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use Carbon\CarbonImmutable;
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
use Modules\Ledger\Exceptions\AccountInUseException;
use Modules\Ledger\Exceptions\MissingSystemAccountException;
use Modules\Ledger\Models\LedgerAccount;
use Tests\TestCase;

class LedgerAccountProvisioningTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
    }

    public function test_provisions_required_system_accounts_idempotently(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);

        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);
        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        $this->assertSame(AccountType::ASSET->value, $clearing->type);
        $this->assertSame(NormalBalance::DEBIT->value, $clearing->normal_balance);
        $this->assertTrue($clearing->is_system);

        $this->assertSame(AccountType::LIABILITY->value, $liability->type);
        $this->assertSame(NormalBalance::CREDIT->value, $liability->normal_balance);
        $this->assertTrue($liability->is_system);

        // Idempotent re-run must not create duplicate accounts
        $registry->ensureRequiredSystemAccounts($this->tenant->id);
        $this->assertSame(2, LedgerAccount::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_missing_system_account_throws_exception(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);

        $this->expectException(MissingSystemAccountException::class);
        $registry->getAccountByRole($this->tenant->id, 'non_existent_role');
    }

    public function test_account_with_journal_lines_cannot_be_deleted(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);
        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        /** @var LedgerPostingServiceInterface $postingService */
        $postingService = app(LedgerPostingServiceInterface::class);

        $now = CarbonImmutable::now('UTC');
        $draft = new JournalDraftDTO(
            tenantId: $this->tenant->id,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'test-del-1',
            postingType: 'capture',
            currency: 'EUR',
            description: 'Test delete restriction',
            effectiveAt: $now,
            postedAt: $now,
            lines: [
                new JournalLineDTO((int) $clearing->id, JournalDirection::DEBIT, 1000, 'EUR'),
                new JournalLineDTO((int) $liability->id, JournalDirection::CREDIT, 1000, 'EUR'),
            ]
        );

        $postingService->post($draft);

        $this->expectException(AccountInUseException::class);
        $clearing->delete();
    }

    public function test_account_currency_restriction_is_enforced(): void
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

        $multiCurrencyAccount = LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'multi_clearing',
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
        $draft = new JournalDraftDTO(
            tenantId: $this->tenant->id,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'test-curr-1',
            postingType: 'capture',
            currency: 'EUR', // Trying to post EUR to a USD-only account!
            description: 'Test currency restriction',
            effectiveAt: $now,
            postedAt: $now,
            lines: [
                new JournalLineDTO((int) $restrictedAccount->id, JournalDirection::DEBIT, 1000, 'EUR'),
                new JournalLineDTO((int) $multiCurrencyAccount->id, JournalDirection::CREDIT, 1000, 'EUR'),
            ]
        );

        $this->expectException(AccountCurrencyMismatchException::class);
        $postingService->post($draft);
    }
}
