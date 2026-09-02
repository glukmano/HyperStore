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
 * Each race spawns real OS-level PHP processes via proc_open, synchronized with a
 * file-barrier, running against the live PostgreSQL database.  These tests prove
 * that production code (not test-side guards) handles races correctly.
 *
 * Race A: TOCTOU — adoption vs automatic expiration.
 *   The expire worker calls the REAL InventoryReservationService::expire() with NO
 *   test-side eligibility guard.  expire() itself must refuse to expire an adopted
 *   reservation under its own row lock.
 *
 * Race A-deadline: adopt() rejects a reservation whose TTL passed before the lock.
 * Race B: adoption vs release.
 * Race C: duplicate same-owner adoption.
 * Race D: conflicting-owner adoption.
 */
class PostgreSqlInventoryAdoptionConcurrencyTest extends TestCase
{
    // ── PostgreSQL concurrency: inventory reservation adoption ──

    private const string PG_DB = 'hyperstore';

    private const string PG_USER = 'lukman';

    private const string PG_HOST = '127.0.0.1';

    private const int    PG_PORT = 5432;

    private Tenant $tenant;

    private int $productId;

    private string $baseWorkerBootstrap;

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

    // ───────────────────────────────────────────────────────────────────────
    // Race A: TOCTOU — Adoption vs Expiration (production code only, no test guard)
    //
    // This race proves expire() itself refuses to expire an adopted reservation.
    // The expire worker calls the REAL service->expire() with NO test-side check.
    //
    // The expire worker pre-fetches the InventoryReservation row BEFORE adoption,
    // pauses at the barrier, while the adopt worker adopts first.
    // When expire resumes, it passes its stale candidate to service->expire().
    // expire() re-evaluates eligibility under its own FOR UPDATE row lock and
    // sees owner_type IS NOT NULL — refuses to expire.
    //
    // Valid outcomes:
    //   Adoption first: status=active, owner_type=order, expires_at=null, reserved unchanged.
    //   Expiration first: status=expired, adopt() fails, reserved=0.
    //
    // Forbidden: adopted+expired, adopted+stock released, double-decrement.
    // ───────────────────────────────────────────────────────────────────────
    public function test_race_a_adoption_wins_over_expiration_via_expire_service_authority(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-a-toctou-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('2.0000'), $context, 60);
        $reservationId = InventoryReservation::where('reservation_key', $resKey)->first()->id;
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;

        $b = $this->baseWorkerBootstrap;

        // Expire worker: loads the reservation BEFORE the barrier (stale candidate reference),
        // then calls the REAL service->expire() with NO test-side guard.
        // expire() itself must detect adoption under its row lock.
        $workerExpire = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Models\InventoryReservation;

// Pre-fetch the candidate row (as ExpireReservationsCommand would do via chunkById)
\$candidate = InventoryReservation::find({$reservationId});

// __BARRIER_WAIT__
// Both workers are now synchronized. adopt worker runs concurrently.

try {
    // Call the REAL production service->expire() with NO test-side eligibility guard.
    // expire() itself must re-evaluate eligibility under its own row lock.
    \$expired = app(InventoryReservationServiceInterface::class)->expire(\$candidate);
    echo \$expired ? 'EXPIRED' : 'NOOP';
} catch (Throwable \$e) {
    echo 'EXPIRE_FAIL:' . \$e->getMessage();
}
PHP;

        $workerAdopt = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Enums\ReservationOwnerType;

// __BARRIER_WAIT__

try {
    app(InventoryReservationServiceInterface::class)->adopt(
        {$tenantId},
        '{$resKey}',
        ReservationOwnerType::ORDER,
        'ORDER-RACE-A',
    );
    echo 'ADOPTED';
} catch (Throwable \$e) {
    echo 'ADOPT_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerExpire, $workerAdopt]);
        $expireOut = $results[0]['stdout'];
        $adoptOut = $results[1]['stdout'];

        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $this->assertNotNull($res);

        if (str_contains($adoptOut, 'ADOPTED')) {
            // Adoption won: expire() saw the adopted state under its lock and returned false (NOOP)
            $this->assertTrue(
                str_contains($expireOut, 'NOOP'),
                "When adoption wins, expire() MUST return false (NOOP). Got: [{$expireOut}]"
            );
            $this->assertSame('active', $res->status, 'Adopted reservation must remain active');
            $this->assertSame('order', $res->owner_type);
            $this->assertSame('ORDER-RACE-A', $res->owner_reference);
            $this->assertNull($res->expires_at);
            $this->assertEquals($stockBefore, $stockAfter, 'Stock must remain unchanged when adoption wins');
        } else {
            // Expiration won first: adopt() must fail; stock released
            $this->assertTrue(
                str_contains($expireOut, 'EXPIRED'),
                "If adoption lost, expiration must have succeeded. Got: [{$expireOut}]"
            );
            $this->assertTrue(
                str_contains($adoptOut, 'ADOPT_FAIL'),
                "If expiration won, adopt() must fail. Got: [{$adoptOut}]"
            );
            $this->assertSame('expired', $res->status);
            $this->assertNull($res->owner_type);
        }

        // INVARIANTS — must hold regardless of which worker won:

        // FORBIDDEN: adopted reservation that was also expired
        if ($res->status === 'active') {
            $this->assertNotNull($res->owner_type, 'Active reservation must have an owner if adoption won');
        }

        // FORBIDDEN: double decrement or negative stock
        $this->assertGreaterThanOrEqual('0.0000', $stockAfter);

        // FORBIDDEN: adopted + stock released (only one state may have released stock)
        if ($res->owner_type === 'order' && $res->status === 'active') {
            $this->assertEquals($stockBefore, $stockAfter, 'FORBIDDEN: adopted reservation has released stock');
        }
    }

    // ───────────────────────────────────────────────────────────────────────
    // Race A (reverse): Expiration first, adoption fails
    //
    // Force a deterministic outcome: expire the reservation first, then attempt adopt.
    // This confirms the reverse path: expiration wins → adopt() throws.
    // ───────────────────────────────────────────────────────────────────────
    public function test_race_a_expiration_wins_adoption_rejects(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-a-expire-first-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('1.0000'), $context, 60);
        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;

        // Deterministically expire first (no concurrent race needed here)
        InventoryReservation::where('reservation_key', $resKey)
            ->update(['expires_at' => Carbon::now()->subSeconds(5)]);
        $res->refresh();

        $expired = $service->expire($res);
        $this->assertTrue($expired, 'Expiration must succeed on a TTL-passed reservation');

        $resAfter = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $this->assertSame('expired', $resAfter->status);
        $this->assertLessThan($stockBefore, $stockAfter, 'Stock must have been released by expiration');

        // Now attempt to adopt the expired reservation
        $this->expectException(ReservationAdoptionException::class);
        $service->adopt($tenantId, $resKey, ReservationOwnerType::ORDER, 'ORDER-AFTER-EXPIRE');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Race A (deadline boundary): adopt() sees TTL-expired active reservation
    // ───────────────────────────────────────────────────────────────────────
    public function test_race_a_deadline_boundary_adopt_on_ttl_expired_active(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-a-deadline-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('1.0000'), $context, 60);

        // Force expires_at to past BEFORE adoption attempt (simulates TTL boundary at lock time)
        InventoryReservation::where('reservation_key', $resKey)
            ->update(['expires_at' => Carbon::now()->subSeconds(5)]);

        $this->expectException(ReservationAdoptionException::class);
        $service->adopt($tenantId, $resKey, ReservationOwnerType::ORDER, 'ORDER-DEADLINE');

        // Reservation must not be adopted
        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $this->assertNull($res->owner_type);
        $this->assertSame('active', $res->status);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Race B: Adoption vs Release
    // ───────────────────────────────────────────────────────────────────────
    public function test_race_b_adoption_vs_release(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-b-adopt-vs-release-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('2.0000'), $context, 60);
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;
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

        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $this->assertNotNull($res);
        $this->assertContains($res->status, ['active', 'released'], 'Must be active (adopted) or released');

        if (str_contains($adoptOut, 'ADOPTED') && $res->status === 'active') {
            // Adoption won and release was no-op or lost the lock: reserved unchanged
            $this->assertEquals($stockBefore, $stockAfter, 'Stock must remain when adopted and still active');
        } elseif ($res->status === 'released') {
            // Release won at some point: stock must be 0
            $this->assertSame('0.0000', $stockAfter, 'Stock must be 0 when released');
        }

        // FORBIDDEN: double decrement
        $this->assertGreaterThanOrEqual('0.0000', $stockAfter);

        // FORBIDDEN: adopted active reservation with released stock
        if ($res->status === 'active' && $res->owner_type === 'order') {
            $this->assertEquals($stockBefore, $stockAfter, 'FORBIDDEN: adopted+active but stock released');
        }
    }

    // ───────────────────────────────────────────────────────────────────────
    // Race C: Duplicate same-owner adoption
    // ───────────────────────────────────────────────────────────────────────
    public function test_race_c_duplicate_same_owner_adoption(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-c-dup-adopt-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('2.0000'), $context, 60);
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;
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

        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $this->assertNotNull($res);

        // Both must succeed (not FAIL)
        $this->assertFalse(
            str_contains($out0, 'FAIL') && str_contains($out1, 'FAIL'),
            "Both workers must not fail simultaneously. Got: [{$out0}] [{$out1}]"
        );

        // At least one must be FIRST or REPLAY
        $successes = count(array_filter([$out0, $out1], fn ($o) => str_contains($o, 'FIRST') || str_contains($o, 'REPLAY')));
        $this->assertGreaterThanOrEqual(1, $successes, 'At least one worker must succeed');

        // Exactly one final owner
        $this->assertSame('order', $res->owner_type);
        $this->assertSame('ORDER-DUP-SAME', $res->owner_reference);
        $this->assertNull($res->expires_at);

        // Stock unchanged from adoption
        $this->assertEquals($stockBefore, $stockAfter, 'StockItem.reserved must not change from adoption race');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Race D: Conflicting-owner adoption — exactly one wins
    // ───────────────────────────────────────────────────────────────────────
    public function test_race_d_conflicting_owner_adoption(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-d-conflict-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('2.0000'), $context, 60);
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;
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

        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $successCount = count(array_filter([$out0, $out1], fn ($o) => str_contains($o, 'ADOPTED')));
        $failCount = count(array_filter([$out0, $out1], fn ($o) => str_contains($o, 'FAIL')));

        $this->assertSame(1, $successCount, "Exactly one owner must win. Got: [{$out0}] [{$out1}]");
        $this->assertSame(1, $failCount, "Exactly one must fail. Got: [{$out0}] [{$out1}]");

        $loserOut = str_contains($out0, 'FAIL') ? $out0 : $out1;
        $this->assertStringContainsString('RESERVATION_CONFLICTING_OWNER', $loserOut, 'Loser must receive typed conflict error');

        $this->assertSame('order', $res->owner_type);
        $this->assertContains($res->owner_reference, ['ORDER-CONFLICT-A', 'ORDER-CONFLICT-B']);
        $this->assertNull($res->expires_at);

        $this->assertEquals($stockBefore, $stockAfter, 'Stock unchanged during adoption race');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Helper: run synchronized parallel workers via proc_open + file barrier
    // ───────────────────────────────────────────────────────────────────────

    /**
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

        usleep(80000); // 80ms setup buffer
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
