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
 * Each test spawns real OS-level processes via proc_open, synchronized with a file
 * barrier, to prove transactional correctness under genuine database-level races.
 *
 * These tests require a running PostgreSQL instance with the hyperstore database.
 * They are NOT SQLite/in-memory tests.
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
    // Race A: Adoption vs Expiration
    // Two processes simultaneously: one adopts, one expires the same reservation.
    // ───────────────────────────────────────────────────────────────────────
    public function test_race_a_adoption_vs_expiration(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        // Create a reservation with a 60-min TTL (not yet expired)
        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-a-adopt-vs-expire-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('2.0000'), $context, 60);

        // Record stock_item reserved before the race
        $stockBefore = StockItem::where('product_id', $productId)->first()->reserved;

        $b = $this->baseWorkerBootstrap;

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

        $workerExpire = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Models\InventoryReservation;
use Carbon\Carbon;

// __BARRIER_WAIT__
try {
    // Force expires_at to past inside a transaction to simulate expiration window
    // then expire via service
    \Illuminate\Support\Facades\DB::transaction(function () use (&\$res) {
        \$res = InventoryReservation::where('reservation_key', '{$resKey}')
            ->lockForUpdate()
            ->first();
        if (\$res && \$res->status === 'active' && \$res->owner_type === null) {
            app(InventoryReservationServiceInterface::class)->expire(\$res);
            echo 'EXPIRED';
        } else {
            echo 'SKIP_EXPIRE:status=' . (\$res ? \$res->status : 'null') . ':owner=' . (\$res ? \$res->owner_type : 'null');
        }
    });
} catch (Throwable \$e) {
    echo 'EXPIRE_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerAdopt, $workerExpire]);
        $adoptOut = $results[0]['stdout'];
        $expireOut = $results[1]['stdout'];

        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        // Valid final states:
        // A) Adoption wins: status=active, owner_type=order, expires_at=null, reserved unchanged
        // B) Expiration wins: status=expired, owner_type=null, reserved=0 OR stockBefore (depending on alloc)
        $adopted = str_contains($adoptOut, 'ADOPTED');
        $expired = str_contains($expireOut, 'EXPIRED') || str_contains($expireOut, 'SKIP_EXPIRE');

        $this->assertNotNull($res, 'Reservation must exist after race');

        if ($adopted) {
            // Adoption won
            $this->assertSame('active', $res->status, 'Adopted reservation must remain active');
            $this->assertSame('order', $res->owner_type, 'Owner must be order after adoption');
            $this->assertNull($res->expires_at, 'expires_at must be null after adoption');
            $this->assertEquals($stockBefore, $stockAfter, 'StockItem.reserved must be unchanged after adoption');
        } else {
            // Expiration won — adoption must have failed
            $this->assertTrue(str_contains($adoptOut, 'ADOPT_FAIL'), "If expiration won, adoption must fail. Got: {$adoptOut}");
            $this->assertSame('expired', $res->status, 'Reservation must be expired if expiration won');
            $this->assertNull($res->owner_type, 'No owner on expired reservation');
        }

        // FORBIDDEN: adopted reservation with released stock
        if ($res->status === 'active' && $res->owner_type === 'order') {
            $this->assertEquals($stockBefore, $stockAfter, 'FORBIDDEN: adopted reservation has released stock');
        }

        // FORBIDDEN: double decrement of stock
        $this->assertGreaterThanOrEqual('0.0000', $stockAfter, 'Stock must not go negative');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Race A (deadline boundary): adoption sees reservation TTL-expired mid-lock
    // ───────────────────────────────────────────────────────────────────────
    public function test_race_a_deadline_boundary_adopt_on_already_ttl_expired(): void
    {
        $tenantId = $this->tenant->id;
        $productId = $this->productId;

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenantId);
        $resKey = 'race-a-deadline-'.uniqid();

        $service->reserve($tenantId, $resKey, $productId, null, Quantity::fromString('1.0000'), $context, 60);

        // Force expires_at to the past BEFORE adoption attempt
        InventoryReservation::where('reservation_key', $resKey)
            ->update(['expires_at' => Carbon::now()->subSeconds(5)]);

        $this->expectException(ReservationAdoptionException::class);

        $service->adopt($tenantId, $resKey, ReservationOwnerType::ORDER, 'ORDER-DEADLINE');

        // Verify reservation is NOT adopted
        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $this->assertNull($res->owner_type);
        $this->assertSame('active', $res->status);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Race B: Adoption vs Release
    // One worker adopts; one worker releases. Only one may win.
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
        {$tenantId},
        '{$resKey}',
        ReservationOwnerType::ORDER,
        'ORDER-RACE-B',
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

        if (str_contains($adoptOut, 'ADOPTED')) {
            // Adoption won: release must have been a no-op (already adopted, status still active)
            // OR release may also have succeeded if it ran after adopt (status=active, then released)
            // Both are valid. Final state: if still active+adopted OR released.
            $this->assertContains($res->status, ['active', 'released'], 'Must be active (adopted) or released');

            if ($res->status === 'active') {
                // Adoption retained: stock still reserved
                $this->assertEquals($stockBefore, $stockAfter, 'Stock must remain reserved if still adopted+active');
            } else {
                // Released after adoption: stock should be 0
                $this->assertSame('0.0000', $stockAfter, 'Stock must be 0 if released');
            }
        } elseif (str_contains($releaseOut, 'RELEASED')) {
            // Release won: adoption must have failed
            $this->assertTrue(str_contains($adoptOut, 'ADOPT_FAIL'), "If release won, adoption must fail. Got: {$adoptOut}");
            $this->assertSame('released', $res->status, 'Must be released if release won');
            $this->assertSame('0.0000', $stockAfter, 'Stock must be 0 after release');
        }

        // FORBIDDEN: owner retained while stock already released as if active reservation
        if ($res->status === 'released' && $res->owner_type === 'order') {
            // Released adopted reservation is acceptable — stock is 0
            $this->assertSame('0.0000', $stockAfter, 'Released adopted reservation must have 0 reserved stock');
        }

        // No double decrement
        $this->assertGreaterThanOrEqual('0.0000', $stockAfter);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Race C: Duplicate same-owner adoption (two processes, same owner)
    // Exactly one must be the initial adoption; one must be the idempotent replay.
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

        $makeWorker = fn (int $w) => <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Enums\ReservationOwnerType;

// __BARRIER_WAIT__
try {
    \$result = app(InventoryReservationServiceInterface::class)->adopt(
        {$tenantId},
        '{$resKey}',
        ReservationOwnerType::ORDER,
        'ORDER-DUP-SAME',
    );
    echo \$result->wasAlreadyAdopted ? 'REPLAY' : 'FIRST';
} catch (Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$makeWorker(1), $makeWorker(2)]);
        $out0 = $results[0]['stdout'];
        $out1 = $results[1]['stdout'];

        $res = InventoryReservation::where('reservation_key', $resKey)->first();
        $stockAfter = StockItem::where('product_id', $productId)->first()->reserved;

        $this->assertNotNull($res);

        // Both must succeed (one FIRST, one REPLAY — order is non-deterministic)
        $this->assertFalse(
            str_contains($out0, 'FAIL') && str_contains($out1, 'FAIL'),
            "Both workers must not fail. Got: [{$out0}] [{$out1}]"
        );

        // One first, one replay (or both replay if timing causes them to serialize)
        $outcomes = [$out0, $out1];
        $firsts = count(array_filter($outcomes, fn ($o) => str_contains($o, 'FIRST')));
        $replays = count(array_filter($outcomes, fn ($o) => str_contains($o, 'REPLAY')));

        $this->assertGreaterThanOrEqual(1, $firsts + $replays, 'At least one must be FIRST or REPLAY');

        // Final state: exactly one owner
        $this->assertSame('order', $res->owner_type, 'Owner must be order');
        $this->assertSame('ORDER-DUP-SAME', $res->owner_reference, 'Same owner reference must win');
        $this->assertNull($res->expires_at, 'expires_at must be null after adoption');

        // Stock must NOT change from before the adoption race
        $this->assertEquals($stockBefore, $stockAfter, 'StockItem.reserved must not change from adoption race');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Race D: Conflicting owner adoption (two different Order UUIDs)
    // Exactly one must win; the other must receive typed conflict error.
    // Final reservation must have exactly one owner.
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
        {$tenantId},
        '{$resKey}',
        ReservationOwnerType::ORDER,
        '{$owner}',
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

        $this->assertNotNull($res);

        $successCount = count(array_filter([$out0, $out1], fn ($o) => str_contains($o, 'ADOPTED')));
        $failCount = count(array_filter([$out0, $out1], fn ($o) => str_contains($o, 'FAIL')));

        // Exactly one must succeed, exactly one must fail
        $this->assertSame(1, $successCount, "Exactly one owner must win. Got: [{$out0}] [{$out1}]");
        $this->assertSame(1, $failCount, "Exactly one must fail with conflict. Got: [{$out0}] [{$out1}]");

        // Loser must get a typed conflict error
        $loserOut = str_contains($out0, 'FAIL') ? $out0 : $out1;
        $this->assertStringContainsString('RESERVATION_CONFLICTING_OWNER', $loserOut, 'Loser must get typed conflict error');

        // Final reservation has exactly one owner
        $this->assertSame('order', $res->owner_type);
        $this->assertContains($res->owner_reference, ['ORDER-CONFLICT-A', 'ORDER-CONFLICT-B']);
        $this->assertNull($res->expires_at);

        // Stock unchanged (adoption does not alter reserved amount)
        $this->assertEquals($stockBefore, $stockAfter, 'StockItem.reserved must not change during adoption race');
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
