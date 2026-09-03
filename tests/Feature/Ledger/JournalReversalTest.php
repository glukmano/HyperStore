<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use Modules\Ledger\Contracts\AccountBalanceQueryInterface;
use Modules\Ledger\Contracts\JournalReversalServiceInterface;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Exceptions\CrossTenantAccessException;
use Modules\Ledger\Exceptions\JournalAlreadyReversedException;
use Modules\Ledger\Listeners\PaymentEventAdapter;
use Modules\Ledger\Models\JournalEntry;
use Modules\Payment\Events\PaymentCaptured;
use Tests\TestCase;

class JournalReversalTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
    }

    public function test_reversal_creates_inverse_lines_and_links_to_original_without_mutating_original(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        /** @var JournalEntry $original */
        $original = JournalEntry::where('source_uuid', $tx->uuid)->firstOrFail();
        $originalCreatedAt = $original->created_at;

        /** @var JournalReversalServiceInterface $reversalService */
        $reversalService = app(JournalReversalServiceInterface::class);
        $reversalJournal = $reversalService->reverse($this->tenant->id, $original->uuid, 'Customer requested charge dispute resolution');

        $this->assertNotNull($reversalJournal);
        $this->assertSame('reversal', $reversalJournal->posting_type);
        $this->assertSame($original->id, $reversalJournal->reverses_journal_entry_id);
        $this->assertSame($original->uuid, $reversalJournal->source_uuid);

        // Original row must remain completely unchanged (pure append-only)
        $freshOriginal = $original->fresh();
        $this->assertSame($originalCreatedAt->toIso8601String(), $freshOriginal->created_at->toIso8601String());
        $this->assertTrue($freshOriginal->isReversed());

        // Lines must be inverted
        $originalDebit = $original->lines()->where('direction', JournalDirection::DEBIT->value)->first();
        $reversalCredit = $reversalJournal->lines()->where('direction', JournalDirection::CREDIT->value)->first();
        $this->assertSame($originalDebit->ledger_account_id, $reversalCredit->ledger_account_id);
        $this->assertSame($originalDebit->amount_minor, $reversalCredit->amount_minor);

        // Balance after reversal must return to zero
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);
        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        /** @var AccountBalanceQueryInterface $balanceQuery */
        $balanceQuery = app(AccountBalanceQueryInterface::class);
        $this->assertSame(0, $balanceQuery->getBalanceForCurrency($this->tenant->id, (int) $clearing->id, 'EUR')->balanceMinor);
        $this->assertSame(0, $balanceQuery->getBalanceForCurrency($this->tenant->id, (int) $liability->id, 'EUR')->balanceMinor);
    }

    public function test_cannot_reverse_same_journal_twice(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        /** @var JournalEntry $original */
        $original = JournalEntry::where('source_uuid', $tx->uuid)->firstOrFail();

        /** @var JournalReversalServiceInterface $reversalService */
        $reversalService = app(JournalReversalServiceInterface::class);
        $reversalService->reverse($this->tenant->id, $original->uuid, 'First reversal');

        $this->expectException(JournalAlreadyReversedException::class);
        $reversalService->reverse($this->tenant->id, $original->uuid, 'Second reversal attempt');
    }

    public function test_cannot_reverse_journal_from_different_tenant(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        /** @var JournalEntry $original */
        $original = JournalEntry::where('source_uuid', $tx->uuid)->firstOrFail();

        /** @var JournalReversalServiceInterface $reversalService */
        $reversalService = app(JournalReversalServiceInterface::class);

        $this->expectException(CrossTenantAccessException::class);
        $reversalService->reverse(999999, $original->uuid, 'Cross-tenant attempt');
    }
}
