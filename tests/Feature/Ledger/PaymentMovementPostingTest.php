<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use Illuminate\Support\Facades\Queue;
use Modules\Ledger\Contracts\AccountBalanceQueryInterface;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Jobs\PostPaymentFinancialMovementJob;
use Modules\Ledger\Listeners\PaymentEventAdapter;
use Modules\Ledger\Models\JournalEntry;
use Modules\Ledger\Models\JournalLine;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Tests\TestCase;

class PaymentMovementPostingTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
        $this->provisionSystemAccounts();
    }

    public function test_payment_captured_synchronous_adapter_dispatches_queued_job_with_scalar_dto(): void
    {
        Queue::fake();

        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        Queue::assertPushed(PostPaymentFinancialMovementJob::class, function (PostPaymentFinancialMovementJob $job) use ($tx, $payment, $order): bool {
            $dto = $job->movement;

            // Assert DTO contains pure primitive scalars, zero Eloquent models
            $this->assertSame((int) $tx->tenant_id, $dto->tenantId);
            $this->assertSame((string) $payment->uuid, $dto->paymentUuid);
            $this->assertSame((string) $tx->uuid, $dto->transactionUuid);
            $this->assertSame('purchase', $dto->operationType);
            $this->assertSame(5000, $dto->amountMinor);
            $this->assertSame('EUR', $dto->currency);
            $this->assertSame((string) $order->uuid, $dto->orderUuid);

            return true;
        });
    }

    public function test_payment_captured_job_posts_debit_clearing_credit_liability(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        // In synchronous testing without Queue::fake(), the job executes synchronously
        /** @var JournalEntry|null $journal */
        $journal = JournalEntry::where('source_uuid', $tx->uuid)->first();
        $this->assertNotNull($journal);
        $this->assertSame('payment', $journal->source_module);
        $this->assertSame('payment_transaction', $journal->source_type);
        $this->assertSame('capture', $journal->posting_type);
        $this->assertSame(2, $journal->lines()->count());

        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);
        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        $debitLine = $journal->lines()->where('direction', JournalDirection::DEBIT->value)->first();
        $this->assertSame($clearing->id, $debitLine->ledger_account_id);
        $this->assertSame(5000, $debitLine->amount_minor);

        $creditLine = $journal->lines()->where('direction', JournalDirection::CREDIT->value)->first();
        $this->assertSame($liability->id, $creditLine->ledger_account_id);
        $this->assertSame(5000, $creditLine->amount_minor);

        // Verify balance query
        /** @var AccountBalanceQueryInterface $balanceQuery */
        $balanceQuery = app(AccountBalanceQueryInterface::class);
        $clearingBalance = $balanceQuery->getBalanceForCurrency($this->tenant->id, (int) $clearing->id, 'EUR');
        $this->assertSame(5000, $clearingBalance->balanceMinor);

        $liabilityBalance = $balanceQuery->getBalanceForCurrency($this->tenant->id, (int) $liability->id, 'EUR');
        $this->assertSame(5000, $liabilityBalance->balanceMinor);
    }

    public function test_payment_refunded_posts_debit_liability_credit_clearing(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $captureTx] = $this->createPaymentWithTransaction($order, 'capture', 'success', 5000, 'EUR');

        // Execute capture
        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $captureTx));

        // Create and execute refund
        /** @var PaymentTransaction $refundTx */
        $refundTx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'refund',
            'status' => 'success',
            'amount_minor' => 2000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-refund-1',
        ]);

        $adapter->handle(new PaymentRefunded($payment, $refundTx));

        /** @var JournalEntry|null $refundJournal */
        $refundJournal = JournalEntry::where('source_uuid', $refundTx->uuid)->first();
        $this->assertNotNull($refundJournal);
        $this->assertSame('refund', $refundJournal->posting_type);

        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);
        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        $debitLine = $refundJournal->lines()->where('direction', JournalDirection::DEBIT->value)->first();
        $this->assertSame($liability->id, $debitLine->ledger_account_id);
        $this->assertSame(2000, $debitLine->amount_minor);

        $creditLine = $refundJournal->lines()->where('direction', JournalDirection::CREDIT->value)->first();
        $this->assertSame($clearing->id, $creditLine->ledger_account_id);
        $this->assertSame(2000, $creditLine->amount_minor);

        // Remaining liability must now be 3000 (5000 - 2000)
        /** @var AccountBalanceQueryInterface $balanceQuery */
        $balanceQuery = app(AccountBalanceQueryInterface::class);
        $liabilityBalance = $balanceQuery->getBalanceForCurrency($this->tenant->id, (int) $liability->id, 'EUR');
        $this->assertSame(3000, $liabilityBalance->balanceMinor);
    }

    public function test_refund_does_not_require_prior_capture_journal_to_exist(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'partially_refunded',
            'captured_amount_minor' => 5000,
            'refunded_amount_minor' => 1500,
        ]);

        /** @var PaymentTransaction $refundTx */
        $refundTx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'refund',
            'status' => 'success',
            'amount_minor' => 1500,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-refund-delayed',
        ]);

        // Capture event was delayed; refund arrives first in Ledger
        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentPartiallyRefunded($payment, $refundTx));

        /** @var JournalEntry|null $refundJournal */
        $refundJournal = JournalEntry::where('source_uuid', $refundTx->uuid)->first();
        $this->assertNotNull($refundJournal, 'Refund journal must post autonomously even if capture journal is delayed.');
        $this->assertSame('refund', $refundJournal->posting_type);
        $this->assertSame(1500, $refundJournal->lines()->first()->amount_minor);
    }

    public function test_multiple_partial_captures_create_distinct_immutable_journals(): void
    {
        $order = $this->createOrder(10000, 'EUR');
        [$payment, $tx1] = $this->createPaymentWithTransaction($order, 'capture', 'success', 4000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx1));

        /** @var PaymentTransaction $tx2 */
        $tx2 = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'success',
            'amount_minor' => 6000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-cap-2',
        ]);

        $adapter->handle(new PaymentCaptured($payment, $tx2));

        $this->assertSame(2, JournalEntry::where('tenant_id', $this->tenant->id)->count());

        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        /** @var AccountBalanceQueryInterface $balanceQuery */
        $balanceQuery = app(AccountBalanceQueryInterface::class);
        $balance = $balanceQuery->getBalanceForCurrency($this->tenant->id, (int) $liability->id, 'EUR');
        $this->assertSame(10000, $balance->balanceMinor);
    }

    public function test_zero_total_settlement_produces_no_journal_and_no_lines(): void
    {
        $order = $this->createOrder(0, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'zero_total_settlement', 'success', 0, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        $this->assertSame(0, JournalEntry::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, JournalLine::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_authorize_void_failure_and_unknown_produce_no_journals(): void
    {
        $order1 = $this->createOrder(5000, 'EUR');
        [$payment1, $authTx] = $this->createPaymentWithTransaction($order1, 'authorize', 'success', 5000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment1, $authTx));
        $this->assertSame(0, JournalEntry::count());

        $order2 = $this->createOrder(5000, 'EUR');
        [$payment2, $failTx] = $this->createPaymentWithTransaction($order2, 'purchase', 'failure', 5000, 'EUR');
        $adapter->handle(new PaymentCaptured($payment2, $failTx));
        $this->assertSame(0, JournalEntry::count());

        $order3 = $this->createOrder(5000, 'EUR');
        [$payment3, $unknownTx] = $this->createPaymentWithTransaction($order3, 'purchase', 'unknown', 5000, 'EUR');
        $adapter->handle(new PaymentCaptured($payment3, $unknownTx));
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_duplicate_event_delivery_is_idempotent(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(2, JournalLine::count());

        // Second delivery of identical event
        $adapter->handle(new PaymentCaptured($payment, $tx));
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(2, JournalLine::count());
    }
}
