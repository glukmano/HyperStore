<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Enums\ReservationOwnerType;
use Modules\Inventory\Exceptions\ReservationAdoptionException;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;
use Tests\TestCase;

/**
 * True process-level PostgreSQL concurrency tests for InventoryReservation adoption.
 *
 * All races use deterministic two-phase file-flag synchronization:
 *  - Phase flags are written by the first actor after completing its critical step.
 *  - The second actor polls for the flag before proceeding.
 *  - This eliminates timing luck and guarantees the required sequencing.
 *
 * RACE A (adoption-first, deterministic):
 *   T1 Expire worker: load stale candidate → write CANDIDATE_SCANNED → poll for ADOPTION_COMMITTED
 *   T2 Adopt worker:  poll for CANDIDATE_SCANNED → adopt+commit → write ADOPTION_COMMITTED
 *   T3 Expire worker: resume → call real expire($staleCandidate) [ZERO test-side guard]
 *   Expected: expire() returns false (NOOP). status=active, owner=order, reserved unchanged.
 *
 * RACE A-REVERSE (expiration-first, deterministic):
 *   T1 Expire worker: load candidate → call real expire($candidate) → signal EXPIRATION_COMMITTED
 *   T2 Adopt worker:  poll for EXPIRATION_COMMITTED → call real adopt(...)
 *   Expected: expire() returns true. adopt() throws typed not-active/expired exception.
 *             status=expired, owner_type=null, reserved=0.
 *   ZERO duplicated expiration business logic in workers.
 *
 * Each test cleans up only the exact reservation IDs it created, so it does not
 * poison the migration test suite.
 */
class PostgreSqlInventoryAdoptionConcurrencyTest extends TestCase
{
    private const string PG_DB = 'hyperstore';

    private const string PG_USER = 'lukman';

    private const string PG_HOST = '127.0.0.1';

    private const int PG_PORT = 5432;

    private Tenant $tenant;

    private int $productId;

    private string $baseWorkerBootstrap;

    /** @var list<int> Reservation IDs created by this test run; cleaned up in tearDown */
    private array $createdReservationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => self::PG_DB,
            'database.connections.pgsql.username' => self::PG_USER,
            'database.connections.pgsql.host' => self::PG_HOST,
            'database.connections.pgsql.port' => self::PG_PORT,
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        $this->seed(ReferenceDataSeeder::class);

        $uid = uniqid('conc_adopt_');
        $this->tenant = Tenant::create(['name' => 'Adopt Conc Tenant', 'slug' => $uid, 'status' => 'active']);

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'ADOPT-CONC-'.strtoupper($uid),
            translations: ['en' => ['name' => 'Adoption Concurrency Product']],
        ));
        $this->productId = $product->id;

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH-'.$uid, 'name' => 'WH', 'country_code' => 'CH']);
        $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC-'.$uid, 'name' => 'SRC', 'priority' => 10]);
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $src->id, 'product_id' => $this->productId, 'on_hand' => '10.0000', 'reserved' => '0.0000']);

        $bp = base_path();
        $db = self::PG_DB;
        $user = self::PG_USER;
        $host = self::PG_HOST;
        $port = self::PG_PORT;

        $this->baseWorkerBootstrap = <<<PHP
<?php
require '{$bp}/vendor/autoload.php';
\$app = require_once '{$bp}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => '{$db}', 'database.connections.pgsql.username' => '{$user}', 'database.connections.pgsql.host' => '{$host}', 'database.connections.pgsql.port' => {$port}]);
\Illuminate\Support\Facades\DB::purge('pgsql');
\Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');
PHP;
    }

    protected function tearDown(): void
    {
        // Clean up ONLY the exact reservation IDs created by this test.
        // Never performs a global/unscoped DELETE.
        if ($this->createdReservationIds !== []) {
            InventoryReservation::whereIn('id', $this->createdReservationIds)->delete();
        }

        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Race A (adoption-first) — DETERMINISTIC TWO-PHASE SEQUENCE
    //
    // Sequence guaranteed by two file flags:
    //   CANDIDATE_SCANNED — written by expire worker after loading stale candidate
    //   ADOPTION_COMMITTED — written by adopt worker after adopt() commits
    //
    // This test PROVES the TOCTOU stale-candidate path is always exercised.
    // ═══════════════════════════════════════════════════════════════════════
    public function test_race_a_deterministic_adoption_first_toctou(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-a-det-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('2.0000'), $context, 60);

        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $reservationId = $res->id;
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;
        $this->createdReservationIds[] = $reservationId;

        // Phase-flag files
        $uid = uniqid('race_a_');
        $flagScanned = sys_get_temp_dir()."/{$uid}_CANDIDATE_SCANNED.flag";
        $flagAdopted = sys_get_temp_dir()."/{$uid}_ADOPTION_COMMITTED.flag";

        $b = $this->baseWorkerBootstrap;

        // ── Expire worker ────────────────────────────────────────────────────
        // Step 1: load stale candidate (before adoption)
        // Step 2: write CANDIDATE_SCANNED  (signals adopt worker to proceed)
        // Step 3: poll for ADOPTION_COMMITTED
        // Step 4: call REAL expire($staleCandidate) — ZERO test-side eligibility guard
        $workerExpire = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Models\InventoryReservation;

// Step 1: Load the stale candidate BEFORE adoption occurs
\$candidate = InventoryReservation::find({$reservationId});
if (!\$candidate) {
    echo 'EXPIRE_ERROR:candidate_not_found';
    exit(1);
}

// Step 2: Signal that the candidate has been scanned with its pre-adoption state
touch('{$flagScanned}');

// Step 3: Wait until adoption has committed
\$waited = 0;
while (!file_exists('{$flagAdopted}')) {
    usleep(5000);
    if (++\$waited > 4000) { echo 'EXPIRE_ERROR:timeout_waiting_for_adoption'; exit(1); }
}

// Step 4: Call REAL expire() with the stale candidate reference — NO test-side guard.
// Production expire() must detect adoption under its own row lock.
try {
    \$expired = app(InventoryReservationServiceInterface::class)->expire(\$candidate);
    echo \$expired ? 'EXPIRED' : 'NOOP';
} catch (Throwable \$e) {
    echo 'EXPIRE_FAIL:' . \$e->getMessage();
}
PHP;

        // ── Adopt worker ─────────────────────────────────────────────────────
        // Step 1: poll for CANDIDATE_SCANNED  (expire worker has the stale ref)
        // Step 2: call REAL adopt() and commit
        // Step 3: write ADOPTION_COMMITTED  (unblocks expire worker)
        $workerAdopt = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Enums\ReservationOwnerType;

// Step 1: Wait until expire worker has loaded the stale candidate
\$waited = 0;
while (!file_exists('{$flagScanned}')) {
    usleep(5000);
    if (++\$waited > 4000) { echo 'ADOPT_ERROR:timeout_waiting_for_scan'; exit(1); }
}

// Step 2: Adopt the reservation (real production path)
try {
    app(InventoryReservationServiceInterface::class)->adopt(
        {$tenantId},
        '{$resKey}',
        ReservationOwnerType::ORDER,
        'ORDER-DET-A',
    );
    echo 'ADOPTED';
} catch (Throwable \$e) {
    echo 'ADOPT_FAIL:' . \$e->getMessage();
    exit(1);
}

// Step 3: Signal that adoption has committed
touch('{$flagAdopted}');
PHP;

        // Start expire worker first (it must load the stale candidate before adopt runs)
        $tmpExpire = sys_get_temp_dir()."/worker_expire_{$uid}.php";
        $tmpAdopt = sys_get_temp_dir()."/worker_adopt_{$uid}.php";
        file_put_contents($tmpExpire, $workerExpire);
        file_put_contents($tmpAdopt, $workerAdopt);

        $pipes = [[], []];
        $descr = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $procExpire = proc_open('php '.escapeshellarg($tmpExpire), $descr, $pipes[0]);
        // Small delay to ensure expire worker loads the candidate before adopt starts
        usleep(60000);
        $procAdopt = proc_open('php '.escapeshellarg($tmpAdopt), $descr, $pipes[1]);

        $expireOut = stream_get_contents($pipes[0][1]);
        $expireErr = stream_get_contents($pipes[0][2]);
        $adoptOut = stream_get_contents($pipes[1][1]);

        foreach ([0, 1] as $i) {
            fclose($pipes[$i][0]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
        }

        proc_close($procExpire);
        proc_close($procAdopt);
        @unlink($tmpExpire);
        @unlink($tmpAdopt);
        @unlink($flagScanned);
        @unlink($flagAdopted);

        // ── Assertions ───────────────────────────────────────────────────────

        // Prove the stale-candidate path was exercised: adopt worker MUST have adopted first
        $this->assertSame('ADOPTED', $adoptOut,
            "Adopt worker MUST succeed (adoption-first sequence). Got: [{$adoptOut}]");

        // expire() MUST return false — production code detected the adopted state
        $this->assertSame('NOOP', $expireOut,
            "expire() MUST return NOOP (false) when reservation was adopted before its lock. Got: [{$expireOut}]");

        // Final DB state
        $final = InventoryReservation::find($reservationId);
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $this->assertNotNull($final);
        $this->assertSame('active', $final->status, 'status must be active');
        $this->assertSame('order', $final->owner_type, 'owner_type must be order');
        $this->assertSame('ORDER-DET-A', $final->owner_reference, 'owner_reference must match');
        $this->assertNull($final->expires_at, 'expires_at must be null (adopted)');
        $this->assertEquals($stockBefore, $stockAfter, 'StockItem.reserved must be unchanged');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Race A-reverse (expiration-first) — DETERMINISTIC PROCESS-LEVEL PROOF
    //
    // Sequence guaranteed by EXPIRATION_COMMITTED flag:
    //   T1: force TTL past → call REAL InventoryReservationServiceInterface::expire($candidate)
    //       → expire() commits → write EXPIRATION_COMMITTED flag
    //   T2: poll for EXPIRATION_COMMITTED → call REAL InventoryReservationServiceInterface::adopt(...)
    //       → adopt() sees status=expired → throws typed ReservationAdoptionException
    //
    // ZERO duplicated expiration business logic in workers.
    // BOTH workers call exclusively production Inventory service contracts.
    // ═══════════════════════════════════════════════════════════════════════
    public function test_race_a_reverse_deterministic_expiration_first(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-a-rev-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('1.0000'), $context, 60);

        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $reservationId = $res->id;
        $this->createdReservationIds[] = $reservationId;

        // Force expires_at into the past so expiration is eligible
        InventoryReservation::where('id', $reservationId)
            ->update(['expires_at' => Carbon::now()->subSeconds(10)]);

        $uid = uniqid('race_ar_');
        $flagCommitted = sys_get_temp_dir()."/{$uid}_EXPIRATION_COMMITTED.flag";

        $b = $this->baseWorkerBootstrap;

        // ── Expire worker ─────────────────────────────────────────────────────
        // Calls the REAL production InventoryReservationServiceInterface::expire()
        // with ZERO duplicated business logic. Signals EXPIRATION_COMMITTED upon commit.
        $workerExpire = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Models\InventoryReservation;

\$candidate = InventoryReservation::find({$reservationId});
if (!\$candidate) {
    echo 'EXPIRE_ERROR:candidate_not_found';
    exit(1);
}

try {
    \$expired = app(InventoryReservationServiceInterface::class)->expire(\$candidate);
    touch('{$flagCommitted}');
    echo \$expired ? 'EXPIRED' : 'NOOP';
} catch (Throwable \$e) {
    echo 'EXPIRE_FAIL:' . \$e->getMessage();
}
PHP;

        // ── Adopt worker ──────────────────────────────────────────────────────
        // Waits until expiration has committed (EXPIRATION_COMMITTED), then calls
        // the REAL production InventoryReservationServiceInterface::adopt().
        $workerAdopt = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Enums\ReservationOwnerType;

\$waited = 0;
while (!file_exists('{$flagCommitted}')) {
    usleep(5000);
    if (++\$waited > 4000) { echo 'ADOPT_ERROR:timeout_waiting_for_expiration'; exit(1); }
}

try {
    app(InventoryReservationServiceInterface::class)->adopt(
        {$tenantId},
        '{$resKey}',
        ReservationOwnerType::ORDER,
        'ORDER-REV',
    );
    echo 'ADOPTED'; // Must NOT happen
} catch (Throwable \$e) {
    echo 'ADOPT_FAIL:' . \$e->getMessage();
}
PHP;

        $tmpExpire = sys_get_temp_dir()."/worker_expire_{$uid}.php";
        $tmpAdopt = sys_get_temp_dir()."/worker_adopt_{$uid}.php";
        file_put_contents($tmpExpire, $workerExpire);
        file_put_contents($tmpAdopt, $workerAdopt);

        $pipes = [[], []];
        $descr = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        // Start expire worker first
        $procExpire = proc_open('php '.escapeshellarg($tmpExpire), $descr, $pipes[0]);
        // Start adopt worker; it polls for EXPIRATION_COMMITTED
        $procAdopt = proc_open('php '.escapeshellarg($tmpAdopt), $descr, $pipes[1]);

        $expireOut = stream_get_contents($pipes[0][1]);
        $adoptOut = stream_get_contents($pipes[1][1]);

        foreach ([0, 1] as $i) {
            fclose($pipes[$i][0]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
        }

        proc_close($procExpire);
        proc_close($procAdopt);
        @unlink($tmpExpire);
        @unlink($tmpAdopt);
        @unlink($flagCommitted);

        // ── Assertions ───────────────────────────────────────────────────────

        // Expire MUST have committed successfully via production service
        $this->assertSame('EXPIRED', $expireOut,
            "Expire worker MUST report EXPIRED via production service. Got: [{$expireOut}]");

        // Adopt MUST have failed with a typed exception (not 'ADOPTED')
        $this->assertStringContainsString('ADOPT_FAIL', $adoptOut,
            "Adopt worker MUST fail after expiration committed. Got: [{$adoptOut}]");
        $this->assertStringContainsString('RESERVATION_NOT_ACTIVE', $adoptOut,
            "Adopt worker MUST receive RESERVATION_NOT_ACTIVE typed exception. Got: [{$adoptOut}]");
        $this->assertStringNotContainsString('ADOPTED', $adoptOut,
            'Adopt must NOT succeed when reservation is expired');

        // Final DB state
        $final = InventoryReservation::find($reservationId);
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $this->assertNotNull($final);
        $this->assertSame('expired', $final->status, 'status must be expired');
        $this->assertNull($final->owner_type, 'owner_type must be null (not adopted)');
        $this->assertSame('0.0000', $stockAfter, 'StockItem.reserved must be 0 after expiration');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Race A-deadline: adopt() rejects active reservation with passed TTL
    // ═══════════════════════════════════════════════════════════════════════
    public function test_race_a_deadline_boundary_adopt_on_ttl_expired_active(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-a-dl-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('1.0000'), $context, 60);
        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $this->createdReservationIds[] = $res->id;

        // Force expires_at to past (cleanup command has not yet run)
        InventoryReservation::where('id', $res->id)
            ->update(['expires_at' => Carbon::now()->subSeconds(5)]);

        $this->expectException(ReservationAdoptionException::class);
        $service->adopt($tenantId, $resKey, ReservationOwnerType::ORDER, 'ORDER-DEADLINE');

        // Verify not adopted
        $final = InventoryReservation::find($res->id);
        $this->assertNull($final->owner_type);
        $this->assertSame('active', $final->status);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Race B: Adoption vs Release
    // ═══════════════════════════════════════════════════════════════════════
    public function test_race_b_adoption_vs_release(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-b-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('2.0000'), $context, 60);
        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;
        $this->createdReservationIds[] = $res->id;

        $b = $this->baseWorkerBootstrap;

        $workerAdopt = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Enums\ReservationOwnerType;

// __BARRIER_WAIT__
try {
    app(InventoryReservationServiceInterface::class)->adopt(
        {$tenantId}, '{$resKey}', ReservationOwnerType::ORDER, 'ORDER-RACE-B'
    );
    echo 'ADOPTED';
} catch (Throwable \$e) {
    echo 'ADOPT_FAIL:' . \$e->getMessage();
}
PHP;

        $workerRelease = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;

// __BARRIER_WAIT__
try {
    \$released = app(InventoryReservationServiceInterface::class)->release({$tenantId}, '{$resKey}');
    echo \$released ? 'RELEASED' : 'RELEASE_NOOP';
} catch (Throwable \$e) {
    echo 'RELEASE_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerAdopt, $workerRelease]);
        $adoptOut = $results[0]['stdout'];
        $releaseOut = $results[1]['stdout'];

        $final = InventoryReservation::find($res->id);
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $this->assertNotNull($final);
        $this->assertContains($final->status, ['active', 'released']);

        // Stock accounting invariant
        if ($final->status === 'active' && $final->owner_type === 'order') {
            $this->assertEquals($stockBefore, $stockAfter, 'Adopted+active: stock unchanged');
        } elseif ($final->status === 'released') {
            $this->assertSame('0.0000', $stockAfter, 'Released: stock must be 0');
        }

        // No double decrement
        $this->assertGreaterThanOrEqual('0.0000', $stockAfter);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Race C: Duplicate same-owner adoption
    // ═══════════════════════════════════════════════════════════════════════
    public function test_race_c_duplicate_same_owner_adoption(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-c-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('2.0000'), $context, 60);
        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;
        $this->createdReservationIds[] = $res->id;

        $b = $this->baseWorkerBootstrap;

        $makeWorker = fn () => <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Enums\ReservationOwnerType;

// __BARRIER_WAIT__
try {
    \$result = app(InventoryReservationServiceInterface::class)->adopt(
        {$tenantId}, '{$resKey}', ReservationOwnerType::ORDER, 'ORDER-DUP-SAME'
    );
    echo \$result->wasAlreadyAdopted ? 'REPLAY' : 'FIRST';
} catch (Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$makeWorker(), $makeWorker()]);
        $out0 = $results[0]['stdout'];
        $out1 = $results[1]['stdout'];

        $final = InventoryReservation::find($res->id);
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        // Both must not fail
        $this->assertFalse(
            str_contains($out0, 'FAIL') && str_contains($out1, 'FAIL'),
            "Both must not fail. Got: [{$out0}] [{$out1}]"
        );

        // Exactly one final owner
        $this->assertSame('order', $final->owner_type);
        $this->assertSame('ORDER-DUP-SAME', $final->owner_reference);
        $this->assertNull($final->expires_at);

        // Stock unchanged from adoption
        $this->assertEquals($stockBefore, $stockAfter, 'Stock unchanged during duplicate adoption race');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Race D: Conflicting-owner adoption
    // ═══════════════════════════════════════════════════════════════════════
    public function test_race_d_conflicting_owner_adoption(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-d-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('2.0000'), $context, 60);
        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;
        $this->createdReservationIds[] = $res->id;

        $b = $this->baseWorkerBootstrap;

        $makeWorker = fn (string $owner) => <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Enums\ReservationOwnerType;

// __BARRIER_WAIT__
try {
    app(InventoryReservationServiceInterface::class)->adopt(
        {$tenantId}, '{$resKey}', ReservationOwnerType::ORDER, '{$owner}'
    );
    echo 'ADOPTED:{$owner}';
} catch (Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([
            $makeWorker('ORDER-CONFLICT-A'),
            $makeWorker('ORDER-CONFLICT-B'),
        ]);
        $out0 = $results[0]['stdout'];
        $out1 = $results[1]['stdout'];

        $final = InventoryReservation::find($res->id);
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $successCount = count(array_filter([$out0, $out1], fn ($o) => str_contains($o, 'ADOPTED')));
        $failCount = count(array_filter([$out0, $out1], fn ($o) => str_contains($o, 'FAIL')));

        $this->assertSame(1, $successCount, "Exactly one owner must win. Got: [{$out0}] [{$out1}]");
        $this->assertSame(1, $failCount, "Exactly one must fail.      Got: [{$out0}] [{$out1}]");

        $loserOut = str_contains($out0, 'FAIL') ? $out0 : $out1;
        $this->assertStringContainsString('RESERVATION_CONFLICTING_OWNER', $loserOut,
            'Loser must receive typed conflict error');

        $this->assertSame('order', $final->owner_type);
        $this->assertContains($final->owner_reference, ['ORDER-CONFLICT-A', 'ORDER-CONFLICT-B']);
        $this->assertNull($final->expires_at);
        $this->assertEquals($stockBefore, $stockAfter, 'Stock unchanged during conflicting adoption race');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Run workers synchronized by a single shared barrier file.
     * Used for races where order is non-deterministic (B, C, D).
     *
     * @param  list<string>  $scripts
     * @return list<array{exit_code: int, stdout: string, stderr: string}>
     */
    private function runSynchronizedParallelWorkers(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/barrier_adopt_'.uniqid().'.flag';
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace(
                '// __BARRIER_WAIT__',
                "while (!file_exists('{$barrierFile}')) { usleep(1000); }",
                $script
            );

            $tmpFile = sys_get_temp_dir()."/worker_adopt_{$idx}_".uniqid().'.php';
            file_put_contents($tmpFile, $syncedScript);

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open('php '.escapeshellarg($tmpFile), $descriptors, $pipes[$idx]);
            $processes[$idx] = ['resource' => $proc, 'tmp_file' => $tmpFile];
        }

        usleep(80000);
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
            $results[$idx] = ['exit_code' => $exitCode, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
        }

        @unlink($barrierFile);

        return $results;
    }
}
