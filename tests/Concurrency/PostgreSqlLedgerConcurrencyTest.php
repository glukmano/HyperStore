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
use Modules\Ledger\Contracts\JournalReversalServiceInterface;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Exceptions\CrossTenantAccessException;
use Modules\Ledger\Listeners\PaymentEventAdapter;
use Modules\Ledger\Models\JournalEntry;
use Modules\Ledger\Models\JournalLine;
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
            if (trim($res['stdout']) === 'REVERSED') {
                $reversedCount++;
            }
            if (str_contains($res['stdout'], 'ALREADY_REVERSED')) {
                $alreadyReversedCount++;
            }
        }

        $this->assertSame(1, $reversedCount);
        $this->assertSame(1, $alreadyReversedCount);
        $this->assertSame(1, JournalEntry::where('reverses_journal_entry_id', $original->id)->count());
    }

    public function test_race_e_posting_and_reversal_race_maintains_consistent_state(): void
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

        // Reversal succeeds against committed original
        $reversalService = app(JournalReversalServiceInterface::class);
        $reversal = $reversalService->reverse($this->tenant->id, $original->uuid, 'Post-commit reversal');

        $this->assertSame($original->id, $reversal->reverses_journal_entry_id);
        $this->assertTrue($original->fresh()->isReversed());
    }

    public function test_race_f_tenant_a_journal_line_referencing_tenant_b_account_fails_closed(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $clearingA = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);

        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tb-'.uniqid(), 'status' => 'active']);
        $registry->ensureRequiredSystemAccounts($tenantB->id);
        $liabilityB = $registry->getAccountByRole($tenantB->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        $postingService = app(LedgerPostingServiceInterface::class);

        $draft = new JournalDraftDTO(
            tenantId: $this->tenant->id,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'cross-inject-1',
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

        $this->expectException(CrossTenantAccessException::class);
        $postingService->post($draft);
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
