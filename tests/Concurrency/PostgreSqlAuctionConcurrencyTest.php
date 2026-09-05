<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Models\Auction;
use Modules\Auctions\Models\Bid;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\CustomerProfile;
use Tests\TestCase;

/**
 * Owner Delta §5/§6/§9: two real OS processes racing to place a bid on the
 * same Auction must not both become the winning bid — the Auction row lock
 * must actually serialize concurrent bid placement, and no historical Bid
 * row is ever mutated by the race.
 */
class PostgreSqlAuctionConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private Auction $auction;

    private CustomerProfile $profileA;

    private CustomerProfile $profileB;

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
            $this->markTestSkipped('PostgreSqlAuctionConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Auction Concurrency Tenant', 'slug' => 'auc-cc-'.uniqid()]);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'AUC_CC_'.uniqid(), 'name' => 'Store', 'slug' => 'auc-cc-store-'.uniqid(), 'status' => 'active']);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'AUC-CC-ITEM', 'name' => 'Item', 'slug' => 'auc-cc-item-'.uniqid(), 'product_type' => 'auction', 'status' => 'active']);

        $this->auction = Auction::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'currency' => 'USD',
            'starts_at' => CarbonImmutable::now()->subMinute(),
            'ends_at' => CarbonImmutable::now()->addHour(),
            'starting_price_minor' => 1000,
            'bid_increment_minor' => 100,
            'status' => AuctionStatus::Active,
        ]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->profileA = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $userA->id]);
        $this->profileB = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $userB->id]);
    }

    /**
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/auc_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_auc_{$idx}_".uniqid().'.php';
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

    /**
     * Both processes attempt the SAME bid amount, exactly at the floor
     * computed from the auction's ORIGINAL current_price. Without real
     * serialization, a lost-update race would let both succeed (each
     * reading the same stale current_price before either commits). With
     * the row lock working, exactly one succeeds; the second is correctly
     * rejected once it observes the first's now-current price via
     * BidTooLowException (its own bid no longer clears the incremented
     * floor) — proving no lost update, not merely "both eventually wrote
     * something."
     */
    public function test_two_concurrent_bids_at_the_same_amount_do_not_both_win(): void
    {
        $bootstrap = $this->getBootstrapScript();
        $auctionId = $this->auction->id;
        $tenantId = $this->tenant->id;

        $workerCode = function (int $profileId) use ($bootstrap, $auctionId, $tenantId): string {
            return "{$bootstrap}
\$profile = \\Modules\\Customers\\Models\\CustomerProfile::find({$profileId});
// __BARRIER_WAIT__
try {
    \$bid = app(\\Modules\\Auctions\\Services\\AuctionBiddingService::class)->placeBid({$tenantId}, {$auctionId}, \$profile, 1100);
    echo 'SUCCESS:' . \$bid->id;
    exit(0);
} catch (\\Modules\\Auctions\\Exceptions\\BidTooLowException \$e) {
    echo 'REJECTED';
    exit(1);
}
";
        };

        $results = $this->executeConcurrently([
            $workerCode($this->profileA->id),
            $workerCode($this->profileB->id),
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

        $this->assertSame(1, $successCount, 'Exactly one of the two identical-amount concurrent bids must win — no lost update.');
        $this->assertSame(1, $rejectedCount);

        $this->auction->refresh();
        $this->assertSame(1, Bid::where('auction_id', $this->auction->id)->count());
        $this->assertSame(1100, $this->auction->current_price_minor);

        $winningBid = Bid::find($this->auction->current_bid_id);
        $this->assertSame(1100, $winningBid->amount_minor);
    }
}
