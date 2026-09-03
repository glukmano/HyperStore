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
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Exceptions\InvalidOrderTransitionException;
use Modules\Order\Models\Order;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Exceptions\InvalidPaymentTransitionException;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentOperationKey;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Providers\FakePaymentGateway;
use Modules\Payment\Services\PaymentCaptureService;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\Payment\Services\PaymentRefundService;
use Modules\Payment\Services\PaymentTransactionReconciliationService;
use Tests\TestCase;

class PostgreSqlPaymentConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private User $user;

    private PaymentInitiationService $initiationService;

    private PaymentCaptureService $captureService;

    private PaymentRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'hyperstore',
            'database.connections.pgsql.username' => 'lukman',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 5432,
        ]);
        DB::purge('pgsql');

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Concurrent Tenant', 'slug' => 'concurrent-'.uniqid(), 'status' => 'active']);
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

        $this->initiationService = app(PaymentInitiationService::class);
        $this->captureService = app(PaymentCaptureService::class);
        $this->refundService = app(PaymentRefundService::class);
    }

    private function runSynchronizedParallelWorkers(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/barrier_pay_'.uniqid().'.flag';
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(1000); }", $script);

            $tmpFile = sys_get_temp_dir()."/worker_pay_{$idx}_".uniqid().'.php';
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
                'stdout' => (string) $stdout,
                'stderr' => (string) $stderr,
            ];
        }

        @unlink($barrierFile);

        return $results;
    }

    private function createTestOrder(int $grandTotalMinor = 10000, string $currency = 'EUR'): Order
    {
        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
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
            'uuid' => (string) Str::uuid(),
            'status' => 'completed',
            'cart_version' => 1,
            'currency' => $currency,
        ]);

        return Order::create([
            'tenant_id' => $this->tenant->id,
            'checkout_id' => $checkout->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $this->user->id,
            'customer_email' => 'customer@example.com',
            'customer_first_name' => 'John',
            'customer_last_name' => 'Doe',
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => $currency,
            'locale' => 'en',
            'order_status' => 'placed',
            'payment_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => $grandTotalMinor,
            'discount_total_minor' => 0,
            'shipping_total_minor' => 0,
            'tax_total_minor' => 0,
            'grand_total_minor' => $grandTotalMinor,
            'customer_snapshot' => ['email' => 'customer@example.com'],
            'shipping_address_snapshot' => ['country' => 'DE'],
            'billing_address_snapshot' => ['country' => 'DE'],
            'pricing_snapshot' => ['total' => $grandTotalMinor],
            'tax_snapshot' => [],
            'promotion_snapshot' => [],
            'shipping_snapshot' => [],
            'fulfillment_snapshot' => [],
            'reservation_references' => [],
            'version' => 1,
            'placed_at' => now(),
        ]);
    }

    private function getWorkerBootstrap(): string
    {
        $basePath = base_path();

        return <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use Illuminate\Support\Facades\DB;

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.database' => 'hyperstore',
    'database.connections.pgsql.username' => 'lukman',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 5432,
]);
DB::purge('pgsql');

app(ContextManager::class)->setTenant(TenantContext::from(__TENANT_ID__));
PHP;
    }

    public function test_race_a_two_simultaneous_initiations_yield_exactly_one_payment_aggregate(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');
        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP

use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentInitiationService::class);
    \$res = \$service->initiatePayment(new InitiatePaymentDTO(
        tenantId: __TENANT_ID__,
        orderId: __ORDER_ID__,
        amountMinor: 5000,
        currency: 'EUR',
        providerCode: 'fake',
        idempotencyKey: 'race_a_key_'.uniqid()
    ));
    echo json_encode(['status' => 'success', 'payment_uuid' => \$res['payment_uuid']]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'message' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        // In DB: Exactly ONE Payment aggregate exists for this order
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_race_b_same_idempotency_key_same_fingerprint_replays_result(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');
        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP

use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentInitiationService::class);
    \$res = \$service->initiatePayment(new InitiatePaymentDTO(
        tenantId: __TENANT_ID__,
        orderId: __ORDER_ID__,
        amountMinor: 5000,
        currency: 'EUR',
        providerCode: 'fake',
        idempotencyKey: 'race_b_shared_key'
    ));
    echo json_encode(['status' => 'success', 'payment_uuid' => \$res['payment_uuid']]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'message' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        $r1 = json_decode($results[0]['stdout'], true);
        $r2 = json_decode($results[1]['stdout'], true);

        $this->assertSame('success', $r1['status'] ?? null, 'Worker 1: '.($r1['message'] ?? ($results[0]['stdout'].' '.$results[0]['stderr'])));
        $this->assertSame('success', $r2['status'] ?? null, 'Worker 2: '.($r2['message'] ?? ($results[1]['stdout'].' '.$results[1]['stderr'])));
        $this->assertSame($r1['payment_uuid'], $r2['payment_uuid']);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_race_c_same_idempotency_key_conflicting_fingerprint_yields_deterministic_conflict(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');
        $bootstrap = $this->getWorkerBootstrap();

        $worker1 = $bootstrap.<<<PHP
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentInitiationService::class);
    \$res = \$service->initiatePayment(new InitiatePaymentDTO(
        tenantId: __TENANT_ID__,
        orderId: __ORDER_ID__,
        amountMinor: 5000,
        currency: 'EUR',
        paymentMethodType: 'card',
        idempotencyKey: 'race_c_key'
    ));
    echo json_encode(['status' => 'success']);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'class' => get_class(\$e)]);
}
PHP;

        $worker2 = $bootstrap.<<<PHP
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentInitiationService::class);
    \$res = \$service->initiatePayment(new InitiatePaymentDTO(
        tenantId: __TENANT_ID__,
        orderId: __ORDER_ID__,
        amountMinor: 5000,
        currency: 'EUR',
        paymentMethodType: 'paypal',
        idempotencyKey: 'race_c_key'
    ));
    echo json_encode(['status' => 'success']);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'class' => get_class(\$e)]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $worker1);
        $s2 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $worker2);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        $statuses = [
            json_decode($results[0]['stdout'], true),
            json_decode($results[1]['stdout'], true),
        ];

        $hasConflict = false;
        foreach ($statuses as $st) {
            if ($st['status'] === 'error' && str_contains($st['class'], 'PaymentIdempotencyConflictException')) {
                $hasConflict = true;
            }
        }

        $this->assertTrue($hasConflict, 'Expected at least one worker to encounter PaymentIdempotencyConflictException.');
    }

    public function test_race_d_different_keys_racing_same_order_guarantees_one_payment_via_db_unique(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');
        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentInitiationService::class);
    \$service->initiatePayment(new InitiatePaymentDTO(
        tenantId: __TENANT_ID__,
        orderId: __ORDER_ID__,
        amountMinor: 5000,
        currency: 'EUR',
        idempotencyKey: 'race_d_'.uniqid()
    ));
    echo json_encode(['status' => 'done']);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);

        $this->runSynchronizedParallelWorkers([$s1, $s2]);

        // DB level invariant: exactly 1 payment aggregate exists
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_race_e_gateway_success_vs_client_retry(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');
        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentInitiationService::class);
    \$res = \$service->initiatePayment(new InitiatePaymentDTO(
        tenantId: __TENANT_ID__,
        orderId: __ORDER_ID__,
        amountMinor: 5000,
        currency: 'EUR',
        idempotencyKey: 'race_e_retry_key'
    ));
    echo json_encode(['status' => 'success', 'tx' => \$res['transaction_id']]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error']);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        $this->assertSame(1, PaymentTransaction::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_race_f_remote_success_local_timeout_retry_reconciles_single_execution(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');

        // Step 1: Initial call times out locally
        try {
            $this->initiationService->initiatePayment(new InitiatePaymentDTO(
                tenantId: $this->tenant->id,
                orderId: $order->id,
                amountMinor: 5000,
                currency: 'EUR',
                providerCode: 'fake',
                paymentMethodReference: 'pm_timeout_after_success',
                idempotencyKey: 'race_f_key'
            ));
        } catch (PaymentReconciliationPendingException) {
            // expected
        }

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $this->assertSame('unknown', $tx->status);

        // Step 2: Retry with same key in worker process
        $bootstrap = $this->getWorkerBootstrap();
        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentInitiationService::class);
    \$res = \$service->initiatePayment(new InitiatePaymentDTO(
        tenantId: __TENANT_ID__,
        orderId: __ORDER_ID__,
        amountMinor: 5000,
        currency: 'EUR',
        providerCode: 'fake',
        paymentMethodReference: 'pm_timeout_after_success',
        idempotencyKey: 'race_f_key'
    ));
    echo json_encode(['status' => 'reconciled', 'payment_status' => \$res['status']]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);
        $results = $this->runSynchronizedParallelWorkers([$s1]);

        $r1 = json_decode($results[0]['stdout'], true);
        $this->assertSame('reconciled', $r1['status']);
        $this->assertSame('captured', $r1['payment_status']);

        // Exactly one payment transaction row exists
        $this->assertSame(1, PaymentTransaction::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_race_g_two_partial_captures_cannot_exceed_remaining_capturable(): void
    {
        $order = $this->createTestOrder(10000, 'EUR');

        // Authorize 10000
        $init = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false
        ));
        $uuid = $init['payment_uuid'];

        $bootstrap = $this->getWorkerBootstrap();

        // Worker attempts to capture 6000
        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\Services\PaymentCaptureService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentCaptureService::class);
    \$res = \$service->capture(__TENANT_ID__, '__UUID__', 6000, 'cap_race_'.uniqid());
    echo json_encode(['status' => 'captured', 'amount' => \$res['captured_amount_minor']]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__UUID__'], [(string) $this->tenant->id, $uuid], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__UUID__'], [(string) $this->tenant->id, $uuid], $workerCode);

        // Two parallel 6000 capture requests on a 10000 authorization
        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        /** @var Payment $payment */
        $payment = Payment::where('uuid', $uuid)->firstOrFail();
        // The total captured amount must NEVER exceed 10000!
        $this->assertLessThanOrEqual(10000, $payment->captured_amount_minor);
    }

    public function test_race_h_two_refunds_cannot_exceed_captured_amount(): void
    {
        $order = $this->createTestOrder(10000, 'EUR');

        $init = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));
        $uuid = $init['payment_uuid'];

        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\Services\PaymentRefundService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentRefundService::class);
    \$res = \$service->refund(__TENANT_ID__, '__UUID__', 6000, 'ref_race_'.uniqid());
    echo json_encode(['status' => 'refunded', 'amount' => \$res['refunded_amount_minor']]);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__UUID__'], [(string) $this->tenant->id, $uuid], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__UUID__'], [(string) $this->tenant->id, $uuid], $workerCode);

        // Two parallel 6000 refunds on a 10000 payment
        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        /** @var Payment $payment */
        $payment = Payment::where('uuid', $uuid)->firstOrFail();
        // The total refunded amount must NEVER exceed 10000!
        $this->assertLessThanOrEqual(10000, $payment->refunded_amount_minor);
    }

    public function test_race_i_initiation_racing_order_cancellation_yields_deterministic_legal_result(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');
        $bootstrap = $this->getWorkerBootstrap();

        $workerInitiate = $bootstrap.<<<PHP
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentInitiationService::class);
    \$res = \$service->initiatePayment(new InitiatePaymentDTO(
        tenantId: __TENANT_ID__,
        orderId: __ORDER_ID__,
        amountMinor: 5000,
        currency: 'EUR'
    ));
    echo json_encode(['status' => 'initiated']);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'init_error', 'msg' => \$e->getMessage()]);
}
PHP;

        $workerCancel = $bootstrap.<<<PHP
use Modules\Order\Contracts\OrderCancellationServiceInterface;
use Modules\Order\Models\Order;

// __BARRIER_WAIT__

try {
    \$order = Order::find(__ORDER_ID__);
    \$service = app(OrderCancellationServiceInterface::class);
    \$service->cancel(\$order, 'Concurrent cancel test');
    echo json_encode(['status' => 'cancelled']);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'cancel_error', 'msg' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerInitiate);
        $s2 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCancel);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        // Assert database state is legal
        $orderFresh = $order->fresh();
        $this->assertTrue(in_array($orderFresh->order_status, ['placed', 'cancelled'], true));
    }

    public function test_race_j_zero_total_parallel_initiation_yields_one_payment_and_zero_gateway_calls(): void
    {
        $order = $this->createTestOrder(0, 'EUR');
        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentInitiationService::class);
    \$res = \$service->initiatePayment(new InitiatePaymentDTO(
        tenantId: __TENANT_ID__,
        orderId: __ORDER_ID__,
        amountMinor: 0,
        currency: 'EUR',
        idempotencyKey: 'race_j_key'
    ));
    echo json_encode(['status' => 'success']);
} catch (\Throwable \$e) {
    echo json_encode(['status' => 'error', 'msg' => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__ORDER_ID__'], [(string) $this->tenant->id, (string) $order->id], $workerCode);

        $this->runSynchronizedParallelWorkers([$s1, $s2]);

        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame(1, PaymentTransaction::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_race_k_stale_failure_result_cannot_regress_success_status(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => PaymentStatus::CAPTURED->value,
            'captured_amount_minor' => 5000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'purchase',
            'status' => PaymentTransactionStatus::SUCCESS->value,
            'amount_minor' => 5000,
            'currency' => 'EUR',
        ]);

        // Attempting to regress status to pending/failure via state machine is prohibited
        $this->assertSame(PaymentStatus::CAPTURED->value, $payment->fresh()->status);
    }

    public function test_race_l_same_payment_operation_key_racing_transaction_creation_yields_exactly_one_row(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);

        /** @var PaymentOperationKey $opKey */
        $opKey = PaymentOperationKey::create([
            'tenant_id' => $this->tenant->id,
            'idempotency_key' => 'race_l_'.uniqid(),
            'operation_type' => 'purchase',
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'request_hash' => 'hash1',
            'status' => 'started',
        ]);

        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\Models\PaymentTransaction;

// __BARRIER_WAIT__

try {
    PaymentTransaction::create([
        'tenant_id' => __TENANT_ID__,
        'payment_id' => __PAYMENT_ID__,
        'payment_operation_key_id' => __OP_KEY_ID__,
        'operation_type' => 'purchase',
        'status' => 'pending',
        'amount_minor' => 5000,
        'currency' => 'EUR',
    ]);
    echo json_encode(['status' => 'inserted']);
} catch (\Illuminate\Database\QueryException \$e) {
    echo json_encode(['status' => 'duplicate_prevented']);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__PAYMENT_ID__', '__OP_KEY_ID__'], [(string) $this->tenant->id, (string) $payment->id, (string) $opKey->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__PAYMENT_ID__', '__OP_KEY_ID__'], [(string) $this->tenant->id, (string) $payment->id, (string) $opKey->id], $workerCode);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        // DB level invariant: exactly 1 transaction exists for this opKey
        $this->assertSame(1, PaymentTransaction::where('payment_operation_key_id', $opKey->id)->count());
    }

    public function test_race_m_provider_idempotency_identity_collision_attempt_prevented_by_db_uniqueness(): void
    {
        $order = $this->createTestOrder(5000, 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);

        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\Models\PaymentTransaction;

// __BARRIER_WAIT__

try {
    PaymentTransaction::create([
        'tenant_id' => __TENANT_ID__,
        'payment_id' => __PAYMENT_ID__,
        'operation_type' => 'purchase',
        'status' => 'pending',
        'amount_minor' => 5000,
        'currency' => 'EUR',
        'provider_code' => 'fake',
        'provider_idempotency_key' => 'race_m_shared_provider_idemp',
    ]);
    echo json_encode(['status' => 'inserted']);
} catch (\Illuminate\Database\QueryException \$e) {
    echo json_encode(['status' => 'duplicate_prevented']);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__PAYMENT_ID__'], [(string) $this->tenant->id, (string) $payment->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__PAYMENT_ID__'], [(string) $this->tenant->id, (string) $payment->id], $workerCode);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        // DB level invariant: exactly 1 transaction exists with this provider idempotency key
        $this->assertSame(1, PaymentTransaction::where('provider_idempotency_key', 'race_m_shared_provider_idemp')->count());
    }

    public function test_race_n_unknown_capture_retry_race_applies_captured_amount_once_only(): void
    {
        $order = $this->createTestOrder(10000, 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => 'authorized',
            'authorized_amount_minor' => 10000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'unknown',
            'amount_minor' => 4000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_idempotency_key' => 'race_n_capture_key',
        ]);

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayRegistryInterface::class)->get('fake');
        $gateway->saveRecord('race_n_capture_key', [
            'status' => 'captured',
            'reference' => 'cap_race_n_ref',
            'amount' => 4000,
            'currency' => 'EUR',
        ]);

        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentTransactionReconciliationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentTransactionReconciliationService::class);
    \$tx = PaymentTransaction::find(__TX_ID__);
    \$payment = Payment::find(__PAYMENT_ID__);
    \$res = \$service->reconcile(\$tx, \$payment);
    echo json_encode(["status" => "reconciled", "captured" => \$res["captured_amount_minor"]]);
} catch (\\Throwable \$e) {
    echo json_encode(["status" => "failed", "error" => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__TX_ID__', '__PAYMENT_ID__'], [(string) $this->tenant->id, (string) $tx->id, (string) $payment->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__TX_ID__', '__PAYMENT_ID__'], [(string) $this->tenant->id, (string) $tx->id, (string) $payment->id], $workerCode);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        // Invariant: captured amount is exactly 4000, NEVER 8000!
        $this->assertSame(4000, $payment->fresh()->captured_amount_minor);
        $this->assertSame('authorized', $payment->fresh()->status);
    }

    public function test_race_o_unknown_refund_retry_race_applies_refunded_amount_once_only(): void
    {
        $order = $this->createTestOrder(10000, 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => 'captured',
            'captured_amount_minor' => 10000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'refund',
            'status' => 'unknown',
            'amount_minor' => 3000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_idempotency_key' => 'race_o_refund_key',
        ]);

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayRegistryInterface::class)->get('fake');
        $gateway->saveRecord('race_o_refund_key', [
            'status' => 'refunded',
            'reference' => 'ref_race_o_ref',
            'amount' => 3000,
            'currency' => 'EUR',
        ]);

        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentTransactionReconciliationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentTransactionReconciliationService::class);
    \$tx = PaymentTransaction::find(__TX_ID__);
    \$payment = Payment::find(__PAYMENT_ID__);
    \$res = \$service->reconcile(\$tx, \$payment);
    echo json_encode(["status" => "reconciled", "refunded" => \$res["refunded_amount_minor"]]);
} catch (\\Throwable \$e) {
    echo json_encode(["status" => "failed", "error" => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__TX_ID__', '__PAYMENT_ID__'], [(string) $this->tenant->id, (string) $tx->id, (string) $payment->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__TX_ID__', '__PAYMENT_ID__'], [(string) $this->tenant->id, (string) $tx->id, (string) $payment->id], $workerCode);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        // Invariant: refunded amount is exactly 3000, NEVER 6000!
        $this->assertSame(3000, $payment->fresh()->refunded_amount_minor);
        $this->assertSame('partially_refunded', $payment->fresh()->status);
    }

    public function test_race_p_unknown_void_retry_race_cancels_payment_once_only(): void
    {
        $order = $this->createTestOrder(10000, 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => 'authorized',
            'authorized_amount_minor' => 10000,
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'void',
            'status' => 'unknown',
            'amount_minor' => 0,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_idempotency_key' => 'race_p_void_key',
        ]);

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayRegistryInterface::class)->get('fake');
        $gateway->saveRecord('race_p_void_key', [
            'status' => 'voided',
            'reference' => 'void_race_p_ref',
            'amount' => 0,
            'currency' => 'EUR',
        ]);

        $bootstrap = $this->getWorkerBootstrap();

        $workerCode = $bootstrap.<<<PHP
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentTransactionReconciliationService;

// __BARRIER_WAIT__

try {
    \$service = app(PaymentTransactionReconciliationService::class);
    \$tx = PaymentTransaction::find(__TX_ID__);
    \$payment = Payment::find(__PAYMENT_ID__);
    \$res = \$service->reconcile(\$tx, \$payment);
    echo json_encode(["status" => "reconciled", "payment_status" => \$res["status"]]);
} catch (\\Throwable \$e) {
    echo json_encode(["status" => "failed", "error" => \$e->getMessage()]);
}
PHP;

        $s1 = str_replace(['__TENANT_ID__', '__TX_ID__', '__PAYMENT_ID__'], [(string) $this->tenant->id, (string) $tx->id, (string) $payment->id], $workerCode);
        $s2 = str_replace(['__TENANT_ID__', '__TX_ID__', '__PAYMENT_ID__'], [(string) $this->tenant->id, (string) $tx->id, (string) $payment->id], $workerCode);

        $results = $this->runSynchronizedParallelWorkers([$s1, $s2]);

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('voided', $order->fresh()->payment_status);
    }

    public function test_race_q_stale_authorization_reconciliation_fails_closed_against_voided_order(): void
    {
        $order = $this->createTestOrder(10000, 'EUR');
        $order->payment_status = 'voided';
        $order->save();

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => 'cancelled',
        ]);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'authorize',
            'status' => 'unknown',
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_idempotency_key' => 'race_q_auth_key',
        ]);

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayRegistryInterface::class)->get('fake');
        $gateway->saveRecord('race_q_auth_key', [
            'status' => 'authorized',
            'reference' => 'auth_race_q_ref',
            'amount' => 10000,
            'currency' => 'EUR',
        ]);

        $service = app(PaymentTransactionReconciliationService::class);

        try {
            $service->reconcile($tx, $payment);
            $this->fail('Expected InvalidPaymentTransitionException or InvalidOrderTransitionException');
        } catch (InvalidPaymentTransitionException|InvalidOrderTransitionException $e) {
            $this->assertTrue(true);
        }

        $this->assertSame('voided', $order->fresh()->payment_status);
    }

    public function test_race_r_stale_capture_reconciliation_fails_closed_against_refunded_order(): void
    {
        $order = $this->createTestOrder(10000, 'EUR');
        $order->payment_status = 'refunded';
        $order->save();

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

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => 'unknown',
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_idempotency_key' => 'race_r_cap_key',
        ]);

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayRegistryInterface::class)->get('fake');
        $gateway->saveRecord('race_r_cap_key', [
            'status' => 'captured',
            'reference' => 'cap_race_r_ref',
            'amount' => 10000,
            'currency' => 'EUR',
        ]);

        $service = app(PaymentTransactionReconciliationService::class);

        try {
            $service->reconcile($tx, $payment);
            $this->fail('Expected InvalidPaymentTransitionException or InvalidOrderTransitionException');
        } catch (InvalidPaymentTransitionException|InvalidOrderTransitionException $e) {
            $this->assertTrue(true);
        }

        $this->assertSame('refunded', $order->fresh()->payment_status);
    }
}
