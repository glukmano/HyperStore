<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use Carbon\CarbonImmutable;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\DTOs\PaymentFinancialMovementDTO;
use Modules\Ledger\Enums\AccountStatus;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Enums\NormalBalance;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Exceptions\AccountInUseException;
use Modules\Ledger\Exceptions\MissingSystemAccountException;
use Modules\Ledger\Jobs\PostPaymentFinancialMovementJob;
use Modules\Ledger\Models\JournalEntry;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Policies\PaymentMovementEligibilityPolicy;
use Tests\TestCase;

class LedgerAccountProvisioningTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
    }

    public function test_explicit_provisioning_creates_exactly_two_required_roles(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);

        $this->assertSame(0, LedgerAccount::where('tenant_id', $this->tenant->id)->count());

        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $this->assertSame(2, LedgerAccount::where('tenant_id', $this->tenant->id)->count());

        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);
        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        $this->assertSame(AccountType::ASSET->value, $clearing->type);
        $this->assertSame(NormalBalance::DEBIT->value, $clearing->normal_balance);
        $this->assertSame('payment_clearing', $clearing->role);
        $this->assertTrue($clearing->is_system);

        $this->assertSame(AccountType::LIABILITY->value, $liability->type);
        $this->assertSame(NormalBalance::CREDIT->value, $liability->normal_balance);
        $this->assertSame('customer_funds_liability', $liability->role);
        $this->assertTrue($liability->is_system);
    }

    public function test_repeated_provisioning_is_idempotent(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);

        $registry->ensureRequiredSystemAccounts($this->tenant->id);
        $this->assertSame(2, LedgerAccount::where('tenant_id', $this->tenant->id)->count());

        // Repeated invocation must be a no-op
        $registry->ensureRequiredSystemAccounts($this->tenant->id);
        $this->assertSame(2, LedgerAccount::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_artisan_command_provisions_required_system_accounts(): void
    {
        $this->assertSame(0, LedgerAccount::where('tenant_id', $this->tenant->id)->count());

        $this->artisan('ledger:provision-system-accounts', [
            '--tenant' => $this->tenant->id,
        ])->assertSuccessful();

        $this->assertSame(2, LedgerAccount::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_posting_with_both_roles_provisioned_succeeds(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'capture', 'success', 5000, 'EUR');

        $dto = new PaymentFinancialMovementDTO(
            tenantId: (int) $this->tenant->id,
            paymentUuid: (string) $payment->uuid,
            transactionUuid: (string) $tx->uuid,
            operationType: 'capture',
            amountMinor: 5000,
            currency: 'EUR',
            occurredAtUtc: CarbonImmutable::now('UTC'),
            orderUuid: (string) $order->uuid
        );

        $job = new PostPaymentFinancialMovementJob($dto);
        $job->handle(
            $registry,
            app(LedgerPostingServiceInterface::class),
            app(PaymentMovementEligibilityPolicy::class)
        );

        $this->assertSame(1, JournalEntry::where('source_uuid', $tx->uuid)->count());
    }

    public function test_posting_with_payment_clearing_missing_fails_closed_without_hidden_account_or_journal_creation(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);

        // Only provision customer_funds_liability; payment_clearing is missing!
        LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'customer_funds_liability',
            'name' => 'Customer Funds Liability',
            'type' => AccountType::LIABILITY->value,
            'normal_balance' => NormalBalance::CREDIT->value,
            'role' => SystemAccountRole::CUSTOMER_FUNDS_LIABILITY->value,
            'is_system' => true,
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, LedgerAccount::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, JournalEntry::count());

        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'capture', 'success', 5000, 'EUR');

        $dto = new PaymentFinancialMovementDTO(
            tenantId: (int) $this->tenant->id,
            paymentUuid: (string) $payment->uuid,
            transactionUuid: (string) $tx->uuid,
            operationType: 'capture',
            amountMinor: 5000,
            currency: 'EUR',
            occurredAtUtc: CarbonImmutable::now('UTC'),
            orderUuid: (string) $order->uuid
        );

        $job = new PostPaymentFinancialMovementJob($dto);

        try {
            $job->handle(
                $registry,
                app(LedgerPostingServiceInterface::class),
                app(PaymentMovementEligibilityPolicy::class)
            );
            $this->fail('Expected MissingSystemAccountException was not thrown.');
        } catch (MissingSystemAccountException $e) {
            $this->assertStringContainsString('payment_clearing', $e->getMessage());
        }

        // Must FAIL CLOSED: zero journals created, zero accounts created
        $this->assertSame(0, JournalEntry::count(), 'Financial posting must not create journals when required accounts are missing.');
        $this->assertSame(1, LedgerAccount::where('tenant_id', $this->tenant->id)->count(), 'Financial posting must not silently provision missing system accounts.');
    }

    public function test_posting_with_customer_funds_liability_missing_fails_closed(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);

        // Only provision payment_clearing; customer_funds_liability is missing!
        LedgerAccount::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'payment_clearing',
            'name' => 'Payment Gateway Clearing',
            'type' => AccountType::ASSET->value,
            'normal_balance' => NormalBalance::DEBIT->value,
            'role' => SystemAccountRole::PAYMENT_CLEARING->value,
            'is_system' => true,
            'status' => AccountStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, LedgerAccount::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, JournalEntry::count());

        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'capture', 'success', 5000, 'EUR');

        $dto = new PaymentFinancialMovementDTO(
            tenantId: (int) $this->tenant->id,
            paymentUuid: (string) $payment->uuid,
            transactionUuid: (string) $tx->uuid,
            operationType: 'capture',
            amountMinor: 5000,
            currency: 'EUR',
            occurredAtUtc: CarbonImmutable::now('UTC'),
            orderUuid: (string) $order->uuid
        );

        $job = new PostPaymentFinancialMovementJob($dto);

        try {
            $job->handle(
                $registry,
                app(LedgerPostingServiceInterface::class),
                app(PaymentMovementEligibilityPolicy::class)
            );
            $this->fail('Expected MissingSystemAccountException was not thrown.');
        } catch (MissingSystemAccountException $e) {
            $this->assertStringContainsString('customer_funds_liability', $e->getMessage());
        }

        // Must FAIL CLOSED: zero journals created, zero accounts created
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(1, LedgerAccount::where('tenant_id', $this->tenant->id)->count());
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
}
