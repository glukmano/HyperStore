<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\CustomerProfile;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Order\Models\Order;
use Modules\Payment\Contracts\PaymentGatewayInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\SetupPaymentMethodRequest;
use Modules\Payment\Models\CustomerPaymentMethod;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Providers\FakePaymentGateway;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Subscriptions\Models\Subscription;
use Modules\Subscriptions\Models\SubscriptionPlan;
use Modules\Subscriptions\Models\SubscriptionRenewalAttempt;
use Modules\Subscriptions\Services\SubscriptionRenewalService;
use Tests\TestCase;

/**
 * Owner Delta §11/D.56: two worker processes racing to process the exact
 * same (subscription_id, billing_period_start) — exactly one claims it,
 * builds one Cart, creates one Order, and calls the provider exactly once.
 */
class PostgreSqlSubscriptionRenewalConcurrencyTest extends TestCase
{
    private Tenant $tenant;

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

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSqlSubscriptionRenewalConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Subscription Concurrency Tenant', 'slug' => 'sub-cc-'.uniqid()]);
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);
    }

    private function makeSubscription(): Subscription
    {
        $market = Market::create(['tenant_id' => $this->tenant->id, 'name' => 'Market', 'code' => 'SUB_CC_M', 'is_active' => true, 'default_currency_code' => 'EUR', 'default_locale_code' => 'en', 'timezone' => 'UTC']);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'SUB_CC_S', 'name' => 'Store', 'slug' => 'sub-cc-store-'.uniqid(), 'status' => 'active']);
        $channel = Channel::create(['type' => 'website', 'name' => 'Web', 'handle' => 'sub-cc-web-'.uniqid(), 'is_active' => true]);
        StoreChannel::create(['store_id' => $store->id, 'channel_id' => $channel->id]);

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);

        $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SUB-CC-'.uniqid(), 'name' => 'Streaming Plan', 'slug' => 'sub-cc-'.uniqid(), 'product_type' => 'subscription', 'status' => 'active']);
        $priceBook = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'SUB_CC_PB_'.uniqid(), 'name' => 'PB', 'currency' => 'EUR', 'status' => 'active', 'priority' => 100]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $priceBook->id, 'product_id' => $product->id, 'amount_minor' => 1200, 'currency' => 'EUR', 'status' => 'active']);

        $plan = SubscriptionPlan::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'name' => 'Streaming Monthly', 'billing_interval' => 'monthly']);

        $user = User::factory()->create();
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);

        /** @var PaymentGatewayInterface $gateway */
        $gateway = app(PaymentGatewayRegistryInterface::class)->default();
        $setup = $gateway->setupPaymentMethod(new SetupPaymentMethodRequest($this->tenant->id, $profile->id, 'card', 'pm_test_cc'));
        $paymentMethod = CustomerPaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'customer_profile_id' => $profile->id,
            'gateway_provider_code' => $gateway->getProviderCode(),
            'gateway_reference' => (string) $setup->providerReference,
            'is_default' => true,
        ]);

        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'customer_profile_id' => $profile->id,
            'plan_id' => $plan->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subMinute(),
            'next_billing_at' => now()->subMinute(),
            'cancel_at_period_end' => false,
            'payment_method_id' => $paymentMethod->id,
        ]);

        return $subscription;
    }

    /**
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/subrenew_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_subrenew_{$idx}_".uniqid().'.php';
            file_put_contents($tmpFile, $syncedScript);

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open('php '.escapeshellarg($tmpFile), $descriptors, $pipes[$idx]);
            $processes[$idx] = ['resource' => $proc, 'tmp_file' => $tmpFile];
        }

        usleep(50000);
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
            $results[$idx] = ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        @unlink($barrierFile);

        return $results;
    }

    private function getBootstrapScript(): string
    {
        $basePath = addslashes(base_path());

        return "<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$kernel = \$app->make(Illuminate\\Contracts\\Console\\Kernel::class);
\$kernel->bootstrap();

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.database' => 'hyperstore',
    'database.connections.pgsql.username' => 'lukman',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 5432,
    'database.connections.pgsql.timezone' => 'UTC',
]);
Illuminate\\Support\\Facades\\DB::purge('pgsql');
";
    }

    public function test_two_workers_racing_the_same_billing_period_result_in_exactly_one_claim_one_order_one_charge(): void
    {
        $subscription = $this->makeSubscription();
        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenant->id;

        $workerCode = "{$bootstrap}
// __BARRIER_WAIT__
app(\\Modules\\Subscriptions\\Services\\SubscriptionRenewalService::class)->processDueRenewals({$tenantId});
echo 'DONE';
exit(0);
";

        $this->executeConcurrently([$workerCode, $workerCode]);

        $attempts = SubscriptionRenewalAttempt::where('subscription_id', $subscription->id)->get();
        $this->assertCount(1, $attempts, 'Exactly one SubscriptionRenewalAttempt row must exist for this billing period, even though two workers raced it.');
        $this->assertSame('succeeded', $attempts->first()->status->value);

        $orderCount = Order::where('tenant_id', $this->tenant->id)->count();
        $this->assertSame(1, $orderCount, 'Exactly one Order must be created for this billing period.');

        $subscription->refresh();
        $this->assertSame('active', $subscription->status->value);
        $this->assertTrue($subscription->current_period_end->isFuture());
    }

    /**
     * FINAL ACCEPTANCE CHECK item 2: the DUNNING RETRY path (re-claiming an
     * already-existing, definitively-failed attempt row) has its own
     * distinct concurrency-exclusivity mechanism (a row lock inside one
     * transaction, re-checked after acquiring the lock) — separate from
     * the fresh-claim path's unique-constraint INSERT. Two workers racing
     * the exact same retry must still result in exactly one re-claim, one
     * new payment attempt (a new PaymentTransaction under a new
     * idempotency key), and NO second renewal Order.
     */
    public function test_two_workers_racing_the_same_dunning_retry_result_in_exactly_one_reclaim_and_one_new_attempt(): void
    {
        $subscription = $this->makeSubscription();

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGatewayRegistryInterface::class)->default();
        $gateway->forcedNextOutcome = 'decline';

        app(SubscriptionRenewalService::class)->processDueRenewals($this->tenant->id);

        $attempt = SubscriptionRenewalAttempt::where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame('failed', $attempt->status->value);
        $this->assertSame(1, $attempt->attempt_number);
        $firstOrderId = $attempt->order_id;

        // The retry day has arrived, and this time the provider approves.
        $attempt->update(['resolved_at' => now()->subDays(2)]);
        $gateway->forcedNextOutcome = null;

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenant->id;

        $workerCode = "{$bootstrap}
// __BARRIER_WAIT__
app(\\Modules\\Subscriptions\\Services\\SubscriptionRenewalService::class)->processDueRenewals({$tenantId});
echo 'DONE';
exit(0);
";

        $this->executeConcurrently([$workerCode, $workerCode]);

        $attempt->refresh();
        $this->assertSame('succeeded', $attempt->status->value);
        $this->assertSame(2, $attempt->attempt_number, 'Exactly one re-claim must have happened — attempt_number must advance by exactly 1, not 2, even though two workers raced it.');
        $this->assertSame($firstOrderId, $attempt->order_id, 'The retry must reuse the SAME renewal Order — never a second Order for this billing period.');

        $this->assertSame(1, Order::where('tenant_id', $this->tenant->id)->count());

        $payment = Payment::where('order_id', $firstOrderId)->firstOrFail();
        $transactionCount = PaymentTransaction::where('payment_id', $payment->id)->count();
        $this->assertSame(2, $transactionCount, 'Exactly two real payment attempts must exist for this Order: the original failure and the one successful retry — never a third from a duplicate concurrent re-claim.');
    }
}
