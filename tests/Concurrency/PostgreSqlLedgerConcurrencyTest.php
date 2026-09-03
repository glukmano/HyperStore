<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Ledger\Contracts\AccountBalanceQueryInterface;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Exceptions\CrossTenantAccessException;
use Modules\Ledger\Listeners\PaymentEventAdapter;
use Modules\Ledger\Models\JournalEntry;
use Modules\Ledger\Models\JournalLine;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Order\Models\Order;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Tests\TestCase;

class PostgreSqlLedgerConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'hyperstore',
            'database.connections.pgsql.username' => 'lukman',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 5432,
            'database.connections.pgsql.timezone' => 'UTC',
        ]);
        DB::purge('pgsql');

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Concurrent Ledger Tenant', 'slug' => 'cc-ledger-'.uniqid(), 'status' => 'active']);
        $this->market = Market::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Concurrent Market',
            'code' => 'CC-MKT-'.uniqid(),
            'is_active' => true,
            'default_currency_code' => 'EUR',
            'default_locale_code' => 'en',
            'timezone' => 'Europe/Berlin',
        ]);
        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Concurrent Store',
            'slug' => 'cc-store-'.uniqid(),
            'status' => 'active',
        ]);
        $this->channel = Channel::create([
            'type' => 'website',
            'name' => 'Concurrent Channel',
            'handle' => 'cc-web-'.uniqid(),
            'is_active' => true,
        ]);
        StoreChannel::create([
            'store_id' => $this->store->id,
            'channel_id' => $this->channel->id,
        ]);

        $this->user = User::factory()->create();

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id));

        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);
    }

    private function createOrder(int $grandTotalMinor = 5000, string $currency = 'EUR'): Order
    {
        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => $currency,
            'locale' => 'en',
            'status' => 'converted',
        ]);

        $checkout = CheckoutSession::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => $currency,
            'locale' => 'en',
            'status' => 'completed',
            'grand_total_minor' => $grandTotalMinor,
        ]);

        return Order::create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-'.strtoupper(uniqid()),
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'checkout_id' => $checkout->id,
            'currency' => $currency,
            'locale' => 'en',
            'order_status' => 'placed',
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => $grandTotalMinor,
            'discount_total_minor' => 0,
            'shipping_total_minor' => 0,
            'tax_total_minor' => 0,
            'grand_total_minor' => $grandTotalMinor,
            'customer_snapshot' => ['email' => 'test@example.com'],
            'placed_at' => now(),
        ]);
    }

    private function getWorkerHeader(): string
    {
        $basePath = base_path();

        return <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.database' => 'hyperstore',
    'database.connections.pgsql.username' => 'lukman',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 5432,
    'database.connections.pgsql.timezone' => 'UTC',
]);
\Illuminate\Support\Facades\DB::purge('pgsql');

// __BARRIER_WAIT__
PHP;
    }

    private function runSynchronizedParallelWorkers(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/barrier_ldg_'.uniqid().'.flag';
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(1000); }", $script);

            $tmpFile = sys_get_temp_dir()."/worker_ldg_{$idx}_".uniqid().'.php';
            file_put_contents($tmpFile, $syncedScript);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $cmd = 'php '.escapeshellarg($tmpFile);
            $proc = proc_open($cmd, $descriptors, $pipes[$idx]);
            $processes[$idx] = [
                'resource' => $proc,
                'tmp_file' => $tmpFile,
            ];
        }

        usleep(50000); // 50ms buffer
        touch($barrierFile);

        $results = [];
        foreach ($processes as $idx => $procInfo) {
            $stdout = stream_get_contents($pipes[$idx][1]);
            $stderr = stream_get_contents($pipes[$idx][2]);
            fclose($pipes[$idx][0]);
            fclose($pipes[$idx][1]);
            fclose($pipes[$idx][2]);

            $exitCode = proc_close($procInfo['resource']);
            @unlink($procInfo['tmp_file']);

            $results[$idx] = [
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        @unlink($barrierFile);

        return $results;
    }

    public function test_race_a_same_capture_transaction_delivered_concurrently_yields_exactly_one_journal(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'captured',
            'captured_amount_minor' => 5000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'success',
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-cap-'.uniqid(),
        ]);

        $header = $this->getWorkerHeader();
        $workerScript = <<<PHP
{$header}

try {
    \$payment = Modules\Payment\Models\Payment::find(%d);
    \$tx = Modules\Payment\Models\PaymentTransaction::find(%d);
    \$adapter = app(Modules\Ledger\Listeners\PaymentEventAdapter::class);
    \$adapter->handle(new Modules\Payment\Events\PaymentCaptured(\$payment, \$tx));
    echo "SUCCESS\n";
} catch (\Throwable \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
}
PHP;

        $script1 = sprintf($workerScript, $payment->id, $tx->id);
        $script2 = sprintf($workerScript, $payment->id, $tx->id);

        $results = $this->runSynchronizedParallelWorkers([$script1, $script2]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], "Worker error: {$res['stderr']}");
            $this->assertStringContainsString('SUCCESS', $res['stdout']);
        }

        $this->assertSame(1, JournalEntry::where('source_uuid', $tx->uuid)->count());
        $this->assertSame(2, JournalLine::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_race_b_same_refund_transaction_delivered_concurrently_yields_exactly_one_journal(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'refunded',
            'captured_amount_minor' => 5000,
            'refunded_amount_minor' => 2000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'refund',
            'status' => 'success',
            'amount_minor' => 2000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-ref-'.uniqid(),
        ]);

        $header = $this->getWorkerHeader();
        $workerScript = <<<PHP
{$header}

try {
    \$payment = Modules\Payment\Models\Payment::find(%d);
    \$tx = Modules\Payment\Models\PaymentTransaction::find(%d);
    \$adapter = app(Modules\Ledger\Listeners\PaymentEventAdapter::class);
    \$adapter->handle(new Modules\Payment\Events\PaymentRefunded(\$payment, \$tx));
    echo "SUCCESS\n";
} catch (\Throwable \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
}
PHP;

        $script1 = sprintf($workerScript, $payment->id, $tx->id);
        $script2 = sprintf($workerScript, $payment->id, $tx->id);

        $results = $this->runSynchronizedParallelWorkers([$script1, $script2]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], "Worker error: {$res['stderr']}");
            $this->assertStringContainsString('SUCCESS', $res['stdout']);
        }

        $this->assertSame(1, JournalEntry::where('source_uuid', $tx->uuid)->count());
        $this->assertSame(2, JournalLine::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_race_c_event_posting_racing_replay_command_yields_exactly_one_journal(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'captured',
            'captured_amount_minor' => 5000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'success',
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-cap-'.uniqid(),
        ]);

        $header = $this->getWorkerHeader();
        $eventScript = sprintf(<<<PHP
{$header}

try {
    \$payment = Modules\Payment\Models\Payment::find(%d);
    \$tx = Modules\Payment\Models\PaymentTransaction::find(%d);
    \$adapter = app(Modules\Ledger\Listeners\PaymentEventAdapter::class);
    \$adapter->handle(new Modules\Payment\Events\PaymentCaptured(\$payment, \$tx));
    echo "SUCCESS\n";
} catch (\Throwable \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
}
PHP, $payment->id, $tx->id);

        $replayScript = sprintf(<<<PHP
{$header}

try {
    \Illuminate\Support\Facades\Artisan::call('ledger:replay-unposted-payment-transactions', [
        '--tenant' => %d,
    ]);
    echo "SUCCESS\n";
} catch (\Throwable \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
}
PHP, $this->tenant->id);

        $results = $this->runSynchronizedParallelWorkers([$eventScript, $replayScript]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], "Worker error: {$res['stderr']}");
            $this->assertStringContainsString('SUCCESS', $res['stdout']);
        }

        $this->assertSame(1, JournalEntry::where('source_uuid', $tx->uuid)->count());
    }

    public function test_race_d_same_original_journal_reversed_concurrently_twice_yields_exactly_one_reversal(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'captured',
            'captured_amount_minor' => 5000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'success',
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-cap-'.uniqid(),
        ]);

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        $original = JournalEntry::where('source_uuid', $tx->uuid)->firstOrFail();

        $header = $this->getWorkerHeader();
        $reversalWorker = sprintf(<<<PHP
{$header}

try {
    \$service = app(Modules\Ledger\Contracts\JournalReversalServiceInterface::class);
    \$service->reverse(%d, '%s', 'Concurrent reversal attempt');
    echo "REVERSED\n";
} catch (Modules\Ledger\Exceptions\JournalAlreadyReversedException \$e) {
    echo "ALREADY_REVERSED\n";
} catch (\Throwable \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
}
PHP, $this->tenant->id, $original->uuid);

        $results = $this->runSynchronizedParallelWorkers([$reversalWorker, $reversalWorker]);

        $reversedCount = 0;
        $alreadyReversedCount = 0;

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], "Worker error: {$res['stderr']}");
            if (str_contains($res['stdout'], 'ALREADY_REVERSED')) {
                $alreadyReversedCount++;
            } elseif (trim($res['stdout']) === 'REVERSED') {
                $reversedCount++;
            }
        }

        $this->assertSame(1, $reversedCount);
        $this->assertSame(1, $alreadyReversedCount);
        $this->assertSame(1, JournalEntry::where('reverses_journal_entry_id', $original->id)->count());
    }

    public function test_race_e_true_multi_process_posting_vs_reversal_race(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'captured',
            'captured_amount_minor' => 5000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'success',
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-race-e-'.uniqid(),
        ]);

        $barrierStart = sys_get_temp_dir().'/race_e_start_'.uniqid().'.flag';
        $barrierRelease = sys_get_temp_dir().'/race_e_release_'.uniqid().'.flag';
        @unlink($barrierStart);
        @unlink($barrierRelease);

        $header = $this->getWorkerHeader();

        // Worker A: Posts original journal using a custom barrier that pauses inside the uncommitted transaction
        $workerAScript = sprintf(<<<PHP
{$header}

class TestFileBarrier implements Modules\Ledger\Contracts\LedgerConcurrencyBarrierInterface {
    public function wait(string \$barrierName, int \$timeoutSeconds = 5): void {
        if (\$barrierName === 'after_journal_entry_created') {
            touch('%s');
            \$waited = 0;
            while (!file_exists('%s') && \$waited < 500) {
                usleep(10000);
                \$waited++;
            }
        }
    }
}

try {
    app()->bind(Modules\Ledger\Contracts\LedgerConcurrencyBarrierInterface::class, TestFileBarrier::class);
    \$payment = Modules\Payment\Models\Payment::find(%d);
    \$tx = Modules\Payment\Models\PaymentTransaction::find(%d);
    \$adapter = app(Modules\Ledger\Listeners\PaymentEventAdapter::class);
    \$adapter->handle(new Modules\Payment\Events\PaymentCaptured(\$payment, \$tx));
    echo "POSTED_COMMITTED\n";
} catch (\Throwable \$e) {
    echo "POST_ERROR: " . \$e->getMessage() . "\n";
}
PHP, $barrierStart, $barrierRelease, $payment->id, $tx->id);

        // Worker B: Attempts reversal while Worker A is paused, then signals release, waits for commit, and reverses
        $workerBScript = sprintf(<<<PHP
{$header}

try {
    // 1. Wait until Worker A is paused inside uncommitted transaction
    \$waited = 0;
    while (!file_exists('%s') && \$waited < 500) {
        usleep(10000);
        \$waited++;
    }

    \$service = app(Modules\Ledger\Contracts\JournalReversalServiceInterface::class);
    \$txUuid = '%s';

    // 2. Attempt reversal against uncommitted journal:
    // In READ COMMITTED, the uncommitted entry is invisible
    \$uncommittedEntry = Modules\Ledger\Models\JournalEntry::where('source_uuid', \$txUuid)->first();
    if (\$uncommittedEntry === null) {
        echo "UNCOMMITTED_REVERSAL_REJECTED\n";
    } else {
        echo "UNCOMMITTED_REVERSAL_UNEXPECTEDLY_VISIBLE\n";
    }

    // 3. Signal Worker A to commit
    touch('%s');

    // 4. Wait for Worker A to commit
    \$waitedCommit = 0;
    \$committedJournal = null;
    while (\$committedJournal === null && \$waitedCommit < 500) {
        usleep(10000);
        \$committedJournal = Modules\Ledger\Models\JournalEntry::where('source_uuid', \$txUuid)->first();
        \$waitedCommit++;
    }

    if (\$committedJournal === null) {
        throw new \RuntimeException('Worker A failed to commit journal');
    }

    // 5. Now reverse committed journal
    \$reversal = \$service->reverse(%d, \$committedJournal->uuid, 'Committed reversal attempt');
    echo "COMMITTED_REVERSAL_SUCCEEDED\n";

    // 6. Duplicate reversal must be rejected
    try {
        \$service->reverse(%d, \$committedJournal->uuid, 'Duplicate reversal attempt');
        echo "DUPLICATE_REVERSAL_UNEXPECTEDLY_SUCCEEDED\n";
    } catch (Modules\Ledger\Exceptions\JournalAlreadyReversedException \$e) {
        echo "DUPLICATE_REVERSAL_REJECTED\n";
    }
} catch (\Throwable \$e) {
    echo "REVERSAL_ERROR: " . \$e->getMessage() . "\n";
}
PHP, $barrierStart, $tx->uuid, $barrierRelease, $this->tenant->id, $this->tenant->id);

        $results = $this->runSynchronizedParallelWorkers([$workerAScript, $workerBScript]);

        @unlink($barrierStart);
        @unlink($barrierRelease);

        $this->assertSame(0, $results[0]['exit_code'], "Worker A error: {$results[0]['stderr']}");
        $this->assertSame(0, $results[1]['exit_code'], "Worker B error: {$results[1]['stderr']}");

        $this->assertStringContainsString('POSTED_COMMITTED', $results[0]['stdout']);
        $this->assertStringContainsString('UNCOMMITTED_REVERSAL_REJECTED', $results[1]['stdout']);
        $this->assertStringContainsString('COMMITTED_REVERSAL_SUCCEEDED', $results[1]['stdout']);
        $this->assertStringContainsString('DUPLICATE_REVERSAL_REJECTED', $results[1]['stdout']);

        // Assert database final graph
        $this->assertSame(1, JournalEntry::where('source_uuid', $tx->uuid)->count());
        $original = JournalEntry::where('source_uuid', $tx->uuid)->firstOrFail();
        $this->assertTrue($original->isReversed());
        $this->assertSame(1, JournalEntry::where('reverses_journal_entry_id', $original->id)->count());
    }

    public function test_race_f_tenant_isolation_at_both_application_and_database_engine_levels(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $clearingA = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);

        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tb-'.uniqid(), 'status' => 'active']);
        $registry->ensureRequiredSystemAccounts($tenantB->id);
        $liabilityB = $registry->getAccountByRole($tenantB->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        // 1. Application-level validation test through LedgerPostingService
        $postingService = app(LedgerPostingServiceInterface::class);

        $draft = new JournalDraftDTO(
            tenantId: $this->tenant->id,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'cross-inject-app',
            postingType: 'capture',
            currency: 'EUR',
            description: 'Cross-tenant injection',
            effectiveAt: CarbonImmutable::now(),
            postedAt: CarbonImmutable::now(),
            lines: [
                new JournalLineDTO((int) $clearingA->id, 'debit', 1000, 'EUR'),
                new JournalLineDTO((int) $liabilityB->id, 'credit', 1000, 'EUR'),
            ]
        );

        try {
            $postingService->post($draft);
            $this->fail('Expected CrossTenantAccessException from application layer.');
        } catch (CrossTenantAccessException $e) {
            $this->assertStringContainsString((string) $this->tenant->id, $e->getMessage());
        }

        // 2. Direct database-level composite foreign key test bypassing application layer:
        // Create legitimate Tenant A JournalEntry
        $now = CarbonImmutable::now('UTC');
        $journalAId = DB::table('journal_entries')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'source_module' => 'test',
            'source_type' => 'test',
            'source_uuid' => 'direct-db-fk-test',
            'posting_type' => 'capture',
            'currency' => 'EUR',
            'description' => 'Legitimate Tenant A journal',
            'effective_at' => $now,
            'posted_at' => $now,
            'created_at' => $now,
        ]);

        // 2a. Direct insert of Tenant A journal line referencing Tenant B ledger account
        try {
            DB::table('journal_lines')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'journal_entry_id' => $journalAId,
                'ledger_account_id' => $liabilityB->id, // Tenant B account!
                'direction' => 'credit',
                'amount_minor' => 1000,
                'currency' => 'EUR',
                'description' => 'Direct injection attempt',
                'created_at' => $now,
            ]);
            $this->fail('Expected PostgreSQL foreign key violation on fk_journal_lines_tenant_account.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fk_journal_lines_tenant_account', $e->getMessage());
        }

        // 2b. Direct insert of Tenant B journal line referencing Tenant A journal entry
        try {
            DB::table('journal_lines')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantB->id, // Tenant B line
                'journal_entry_id' => $journalAId, // Tenant A journal!
                'ledger_account_id' => $liabilityB->id,
                'direction' => 'credit',
                'amount_minor' => 1000,
                'currency' => 'EUR',
                'description' => 'Direct injection attempt',
                'created_at' => $now,
            ]);
            $this->fail('Expected PostgreSQL foreign key violation on fk_journal_lines_tenant_entry.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fk_journal_lines_tenant_entry', $e->getMessage());
        }

        // 2c. Direct insert of Tenant B reversal referencing Tenant A journal entry
        try {
            DB::table('journal_entries')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantB->id, // Tenant B journal
                'source_module' => 'test',
                'source_type' => 'test',
                'source_uuid' => 'direct-db-reversal-fk-test',
                'posting_type' => 'reversal',
                'currency' => 'EUR',
                'reverses_journal_entry_id' => $journalAId, // Tenant A journal!
                'description' => 'Cross-tenant reversal injection',
                'effective_at' => $now,
                'posted_at' => $now,
                'created_at' => $now,
            ]);
            $this->fail('Expected PostgreSQL foreign key violation on fk_journal_entries_reversal_tenant.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fk_journal_entries_reversal_tenant', $e->getMessage());
        }
    }

    public function test_race_g_two_partial_captures_concurrently_creates_two_journals_summing_to_total(): void
    {
        $order = $this->createOrder(10000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => 'captured',
            'captured_amount_minor' => 10000,
        ]);

        /** @var PaymentTransaction $tx1 */
        $tx1 = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'success',
            'amount_minor' => 4000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-p1-'.uniqid(),
        ]);

        /** @var PaymentTransaction $tx2 */
        $tx2 = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'success',
            'amount_minor' => 6000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-p2-'.uniqid(),
        ]);

        $header = $this->getWorkerHeader();
        $workerScript = <<<PHP
{$header}

try {
    \$payment = Modules\Payment\Models\Payment::find(%d);
    \$tx = Modules\Payment\Models\PaymentTransaction::find(%d);
    \$adapter = app(Modules\Ledger\Listeners\PaymentEventAdapter::class);
    \$adapter->handle(new Modules\Payment\Events\PaymentCaptured(\$payment, \$tx));
    echo "SUCCESS\n";
} catch (\Throwable \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
}
PHP;

        $script1 = sprintf($workerScript, $payment->id, $tx1->id);
        $script2 = sprintf($workerScript, $payment->id, $tx2->id);

        $results = $this->runSynchronizedParallelWorkers([$script1, $script2]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], "Worker error: {$res['stderr']}");
            $this->assertStringContainsString('SUCCESS', $res['stdout']);
        }

        $this->assertSame(2, JournalEntry::where('tenant_id', $this->tenant->id)->count());

        $balanceQuery = app(AccountBalanceQueryInterface::class);
        $registry = app(LedgerAccountRegistryInterface::class);
        $clearing = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);
        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        $this->assertSame(10000, $balanceQuery->getBalanceForCurrency($this->tenant->id, (int) $clearing->id, 'EUR')->balanceMinor);
        $this->assertSame(10000, $balanceQuery->getBalanceForCurrency($this->tenant->id, (int) $liability->id, 'EUR')->balanceMinor);
    }

    public function test_race_h_two_refund_transactions_concurrently_creates_two_journals_reducing_liability(): void
    {
        $order = $this->createOrder(10000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => 'refunded',
            'captured_amount_minor' => 10000,
            'refunded_amount_minor' => 10000,
        ]);

        /** @var PaymentTransaction $ref1 */
        $ref1 = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'refund',
            'status' => 'success',
            'amount_minor' => 3000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-r1-'.uniqid(),
        ]);

        /** @var PaymentTransaction $ref2 */
        $ref2 = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'refund',
            'status' => 'success',
            'amount_minor' => 7000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-r2-'.uniqid(),
        ]);

        $header = $this->getWorkerHeader();
        $workerScript = <<<PHP
{$header}

try {
    \$payment = Modules\Payment\Models\Payment::find(%d);
    \$tx = Modules\Payment\Models\PaymentTransaction::find(%d);
    \$adapter = app(Modules\Ledger\Listeners\PaymentEventAdapter::class);
    \$adapter->handle(new Modules\Payment\Events\PaymentRefunded(\$payment, \$tx));
    echo "SUCCESS\n";
} catch (\Throwable \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
}
PHP;

        $script1 = sprintf($workerScript, $payment->id, $ref1->id);
        $script2 = sprintf($workerScript, $payment->id, $ref2->id);

        $results = $this->runSynchronizedParallelWorkers([$script1, $script2]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], "Worker error: {$res['stderr']}");
            $this->assertStringContainsString('SUCCESS', $res['stdout']);
        }

        $this->assertSame(2, JournalEntry::where('tenant_id', $this->tenant->id)->count());

        $balanceQuery = app(AccountBalanceQueryInterface::class);
        $registry = app(LedgerAccountRegistryInterface::class);
        $liability = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        // Net liability for refunds only is -10000
        $this->assertSame(-10000, $balanceQuery->getBalanceForCurrency($this->tenant->id, (int) $liability->id, 'EUR')->balanceMinor);
    }

    public function test_race_i_unknown_transaction_produces_no_posting_later_reconciled_success_posts_once(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'purchase',
            'status' => 'unknown',
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-unknown-'.uniqid(),
        ]);

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        $this->assertSame(0, JournalEntry::where('source_uuid', $tx->uuid)->count());

        // Reconciled to success
        $tx->status = 'success';
        $tx->save();
        $payment->status = 'captured';
        $payment->captured_amount_minor = 5000;
        $payment->save();

        $adapter->handle(new PaymentCaptured($payment, $tx));
        $this->assertSame(1, JournalEntry::where('source_uuid', $tx->uuid)->count());
    }

    public function test_race_j_zero_total_settlement_delivered_concurrently_produces_zero_journals_and_zero_lines(): void
    {
        $order = $this->createOrder(0, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 0,
            'currency' => 'EUR',
            'status' => 'captured',
            'captured_amount_minor' => 0,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'zero_total_settlement',
            'status' => 'success',
            'amount_minor' => 0,
            'currency' => 'EUR',
            'provider_code' => 'internal_free',
            'provider_reference' => 'free-'.uniqid(),
        ]);

        $header = $this->getWorkerHeader();
        $workerScript = <<<PHP
{$header}

try {
    \$payment = Modules\Payment\Models\Payment::find(%d);
    \$tx = Modules\Payment\Models\PaymentTransaction::find(%d);
    \$adapter = app(Modules\Ledger\Listeners\PaymentEventAdapter::class);
    \$adapter->handle(new Modules\Payment\Events\PaymentCaptured(\$payment, \$tx));
    echo "SUCCESS\n";
} catch (\Throwable \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
}
PHP;

        $script1 = sprintf($workerScript, $payment->id, $tx->id);
        $script2 = sprintf($workerScript, $payment->id, $tx->id);

        $results = $this->runSynchronizedParallelWorkers([$script1, $script2]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], "Worker error: {$res['stderr']}");
            $this->assertStringContainsString('SUCCESS', $res['stdout']);
        }

        $this->assertSame(0, JournalEntry::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, JournalLine::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_race_k_parallel_required_system_account_provisioning(): void
    {
        // Create a new tenant without any system accounts provisioned yet
        $newTenant = Tenant::create([
            'name' => 'Race K Tenant',
            'slug' => 'race-k-'.uniqid(),
            'status' => 'active',
        ]);

        $header = $this->getWorkerHeader();
        $provisionWorker = sprintf(<<<PHP
{$header}

try {
    \$registry = app(Modules\Ledger\Contracts\LedgerAccountRegistryInterface::class);
    \$registry->ensureRequiredSystemAccounts(%d);
    echo "PROVISIONED\n";
} catch (\Throwable \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
}
PHP, $newTenant->id);

        // Run 2 parallel workers attempting to provision the same tenant at the exact same moment
        $results = $this->runSynchronizedParallelWorkers([$provisionWorker, $provisionWorker]);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], "Worker error: {$res['stderr']}");
            $this->assertStringContainsString('PROVISIONED', $res['stdout']);
        }

        // Must have exactly one payment_clearing and exactly one customer_funds_liability
        $accounts = LedgerAccount::withoutGlobalScopes()->where('tenant_id', $newTenant->id)->get();
        $this->assertCount(2, $accounts);

        $clearing = $accounts->firstWhere('role', SystemAccountRole::PAYMENT_CLEARING->value);
        $liability = $accounts->firstWhere('role', SystemAccountRole::CUSTOMER_FUNDS_LIABILITY->value);

        $this->assertNotNull($clearing);
        $this->assertNotNull($liability);
    }

    public function test_postgresql_immutability_triggers_reject_direct_sql_updates_and_deletes(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'captured',
            'captured_amount_minor' => 5000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'success',
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_reference' => 'ref-imm-'.uniqid(),
        ]);

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        $journal = JournalEntry::where('source_uuid', $tx->uuid)->firstOrFail();
        $line = $journal->lines()->firstOrFail();

        // 1. UPDATE journal_entries
        try {
            DB::statement("UPDATE journal_entries SET description = 'hacked' WHERE id = {$journal->id}");
            $this->fail('Expected QueryException for updating journal_entries');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Financial accounting records are immutable', $e->getMessage());
        }

        // 2. DELETE journal_entries
        try {
            DB::statement("DELETE FROM journal_entries WHERE id = {$journal->id}");
            $this->fail('Expected QueryException for deleting journal_entries');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Financial accounting records are immutable', $e->getMessage());
        }

        // 3. UPDATE journal_lines
        try {
            DB::statement("UPDATE journal_lines SET amount_minor = 99999 WHERE id = {$line->id}");
            $this->fail('Expected QueryException for updating journal_lines');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Financial accounting records are immutable', $e->getMessage());
        }

        // 4. DELETE journal_lines
        try {
            DB::statement("DELETE FROM journal_lines WHERE id = {$line->id}");
            $this->fail('Expected QueryException for deleting journal_lines');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Financial accounting records are immutable', $e->getMessage());
        }
    }
}
