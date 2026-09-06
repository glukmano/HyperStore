<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Models\CheckoutSession;
use Modules\DigitalDelivery\Models\LicenseKeyPool;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Tests\TestCase;

/**
 * Owner Delta §14/D.56: two concurrent paid Orders racing for the single
 * remaining license key — exactly one wins; the "SELECT ... FOR UPDATE
 * SKIP LOCKED" queue-pop idiom must actually serialize, not merely
 * appear to.
 */
class PostgreSqlLicenseAllocationConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private Product $product;

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
            $this->markTestSkipped('PostgreSqlLicenseAllocationConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'License Concurrency Tenant', 'slug' => 'lic-cc-'.uniqid()]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'LIC-CC-'.uniqid(),
            'name' => 'Licensed Software',
            'slug' => 'lic-cc-'.uniqid(),
            'product_type' => 'license',
            'status' => 'active',
        ]);
    }

    private function makeOrderItem(): OrderItem
    {
        $order = Order::create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-'.strtoupper(uniqid()),
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->makeMinimalStoreId(),
            'market_id' => $this->makeMinimalMarketId(),
            'channel_id' => $this->makeMinimalChannelId(),
            'checkout_id' => $this->makeFreshCheckoutId(),
            'currency' => 'USD',
            'locale' => 'en',
            'order_status' => 'placed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => 4000,
            'grand_total_minor' => 4000,
            'customer_snapshot' => ['email' => 'test@example.com'],
            'placed_at' => now(),
        ]);

        return OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_type_snapshot' => 'license',
            'sku_snapshot' => 'LIC-CC-1',
            'name_snapshot' => 'Licensed Software',
            'quantity' => '1',
            'unit_price_minor' => 4000,
            'subtotal_minor' => 4000,
            'total_minor' => 4000,
        ]);
    }

    private int $storeId;

    private int $marketId;

    private int $channelId;

    private function makeMinimalStoreId(): int
    {
        if (isset($this->storeId)) {
            return $this->storeId;
        }
        $store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'LIC_CC', 'name' => 'Store', 'slug' => 'lic-cc-store-'.uniqid(), 'status' => 'active']);

        return $this->storeId = (int) $store->id;
    }

    private function makeMinimalMarketId(): int
    {
        if (isset($this->marketId)) {
            return $this->marketId;
        }
        $market = Market::create(['tenant_id' => $this->tenant->id, 'name' => 'Market', 'code' => 'LIC_M', 'is_active' => true, 'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'timezone' => 'UTC']);

        return $this->marketId = (int) $market->id;
    }

    private function makeMinimalChannelId(): int
    {
        if (isset($this->channelId)) {
            return $this->channelId;
        }
        $channel = Channel::create(['type' => 'website', 'name' => 'Web', 'handle' => 'lic-cc-web-'.uniqid(), 'is_active' => true]);

        return $this->channelId = (int) $channel->id;
    }

    private function makeFreshCheckoutId(): int
    {
        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->makeMinimalStoreId(),
            'market_id' => $this->makeMinimalMarketId(),
            'channel_id' => $this->makeMinimalChannelId(),
            'currency' => 'USD',
            'locale' => 'en',
            'status' => 'converted',
        ]);
        $checkout = CheckoutSession::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->makeMinimalStoreId(),
            'market_id' => $this->makeMinimalMarketId(),
            'channel_id' => $this->makeMinimalChannelId(),
            'currency' => 'USD',
            'locale' => 'en',
            'state' => 'ready_for_order',
        ]);

        return (int) $checkout->id;
    }

    /**
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/lic_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_lic_{$idx}_".uniqid().'.php';
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

    public function test_two_concurrent_order_items_racing_the_last_key_exactly_one_wins(): void
    {
        LicenseKeyPool::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'code_encrypted' => 'KEY-ONLY-ONE', 'status' => 'available']);

        $item1 = $this->makeOrderItem();
        $item2 = $this->makeOrderItem();

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenant->id;
        $productId = $this->product->id;

        $workerCode = function (int $orderItemId) use ($bootstrap, $tenantId, $productId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$key = app(\\Modules\\DigitalDelivery\\Services\\LicenseKeyAllocationService::class)->allocate({$tenantId}, {$productId}, {$orderItemId});
    echo 'SUCCESS:' . \$key->id;
    exit(0);
} catch (\\Modules\\DigitalDelivery\\Exceptions\\DigitalDeliveryException \$e) {
    echo 'REJECTED';
    exit(1);
}
";
        };

        $results = $this->executeConcurrently([
            $workerCode($item1->id),
            $workerCode($item2->id),
        ]);

        $successCount = 0;
        $rejectedCount = 0;
        foreach ($results as $r) {
            if (str_contains($r['stdout'], 'SUCCESS')) {
                $successCount++;
            } elseif (str_contains($r['stdout'], 'REJECTED')) {
                $rejectedCount++;
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one of two concurrent Orders racing the last available license key must win.');
        $this->assertSame(1, $rejectedCount);
        $this->assertSame(1, LicenseKeyPool::where('product_id', $this->product->id)->where('status', 'assigned')->count());
    }

    public function test_duplicate_processing_of_the_same_order_item_returns_the_existing_key_under_concurrency(): void
    {
        LicenseKeyPool::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'code_encrypted' => 'KEY-DUP-A', 'status' => 'available']);
        LicenseKeyPool::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'code_encrypted' => 'KEY-DUP-B', 'status' => 'available']);

        $item = $this->makeOrderItem();

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenant->id;
        $productId = $this->product->id;

        $workerCode = "{$bootstrap}
// __BARRIER_WAIT__
\$key = app(\\Modules\\DigitalDelivery\\Services\\LicenseKeyAllocationService::class)->allocate({$tenantId}, {$productId}, {$item->id});
echo 'SUCCESS:' . \$key->id;
exit(0);
";

        $results = $this->executeConcurrently([$workerCode, $workerCode]);

        $ids = [];
        foreach ($results as $r) {
            if (preg_match('/SUCCESS:(\d+)/', $r['stdout'], $m)) {
                $ids[] = (int) $m[1];
            }
        }

        $this->assertCount(2, $ids);
        $this->assertSame($ids[0], $ids[1], 'A retried allocation for the SAME OrderItem must always return the same key, even under a concurrent race.');
        $this->assertSame(1, LicenseKeyPool::where('assigned_order_item_id', $item->id)->count());
    }
}
