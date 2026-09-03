<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use Modules\Ledger\Models\JournalEntry;
use Tests\TestCase;

class LedgerAuditAndReplayCommandTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
    }

    public function test_audit_detects_unposted_eligible_transaction_without_mutating_data(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        // We did not run the adapter/job, so transaction is unposted
        $this->assertSame(0, JournalEntry::count());

        $exitCode = $this->artisan('ledger:audit-unposted-payment-transactions', [
            '--tenant' => $this->tenant->id,
        ]);

        $exitCode->assertFailed();
        $this->assertSame(0, JournalEntry::count(), 'Audit command must be strictly read-only.');
    }

    public function test_replay_dry_run_simulates_without_mutating_data(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $this->assertSame(0, JournalEntry::count());

        $this->artisan('ledger:replay-unposted-payment-transactions', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, JournalEntry::count(), 'Dry-run must not create any journals.');
    }

    public function test_replay_creates_missing_journal_idempotently(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $this->assertSame(0, JournalEntry::count());

        $this->artisan('ledger:replay-unposted-payment-transactions', [
            '--tenant' => $this->tenant->id,
        ])->assertSuccessful();

        $this->assertSame(1, JournalEntry::count());
        $journal = JournalEntry::where('source_uuid', $tx->uuid)->first();
        $this->assertNotNull($journal);
        $this->assertSame(5000, $journal->lines()->first()->amount_minor);

        // Audit now passes cleanly
        $this->artisan('ledger:audit-unposted-payment-transactions', [
            '--tenant' => $this->tenant->id,
        ])->assertSuccessful();

        // Re-running replay is completely idempotent
        $this->artisan('ledger:replay-unposted-payment-transactions', [
            '--tenant' => $this->tenant->id,
        ])->assertSuccessful();

        $this->assertSame(1, JournalEntry::count());
    }
}
