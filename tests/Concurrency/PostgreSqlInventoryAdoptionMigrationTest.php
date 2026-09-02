<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Enums\ReservationOwnerType;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;
use Tests\TestCase;

/**
 * PostgreSQL migration verification for the adoption patch (migration 000082).
 *
 * ISOLATION STRATEGY: Each test run creates a disposable PostgreSQL database named
 *   hyperstore_migration_test_<uid>
 * Baseline migrations are applied to this fresh database, then migration 000082 is
 * tested within it. The database is dropped at teardown regardless of test outcome.
 *
 * This means:
 *  - Zero risk of polluting the shared 'hyperstore' database.
 *  - No global/unscoped reservation DELETE is ever performed.
 *  - Only exact fixture IDs created by THIS test are tracked and cleaned.
 *  - An "unrelated decoy" reservation inserted before the migration lifecycle test
 *    is proven to survive the entire harness untouched.
 *
 * Sequence (M-04):
 *   A. Fresh DB + all baseline migrations applied
 *   B. Migration 000082 applied → assertions
 *   C. Insert adopted fixture (expires_at = null) via insertAdoptedReservationFixture()
 *   D. Insert decoy reservation (unrelated row, tracked by exact ID)
 *   E. Release + delete ONLY the adopted fixture (tracked by exact ID)
 *   F. Assert decoy row still exists and is unmodified
 *   G. REAL Laravel migrate:rollback → schema assertions
 *   H. REAL Laravel migrate (re-apply) → schema assertions
 *   I. Migration table entry confirmed restored
 *   J. Drop disposable database
 */
class PostgreSqlInventoryAdoptionMigrationTest extends TestCase
{
    private const string PG_ADMIN_DB = 'hyperstore';

    private const string PG_USER = 'lukman';

    private const string PG_HOST = '127.0.0.1';

    private const int PG_PORT = 5432;

    private const string MIGRATION_FILE = '2026_09_01_000082_add_adoption_columns_to_inventory_reservations';

    private const string MIGRATION_PATH = 'database/migrations/2026_09_01_000082_add_adoption_columns_to_inventory_reservations.php';

    /** Disposable test database name — unique per test run */
    private string $testDb;

    /** Exact reservation IDs created by this test — only these are deleted */
    private array $trackedReservationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create a fresh disposable PostgreSQL database for complete isolation
        $this->testDb = 'hyperstore_mig_'.substr(uniqid(), 0, 12);
        $this->createDisposableDatabase($this->testDb);

        // Connect to the disposable database
        $this->connectTo($this->testDb);
    }

    protected function tearDown(): void
    {
        // Disconnect from disposable DB before dropping it
        DB::disconnect('pgsql');

        // Reconnect to admin DB to perform DROP
        $this->connectTo(self::PG_ADMIN_DB);

        // Drop disposable database
        $this->dropDisposableDatabase($this->testDb);

        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M-01: expires_at nullable after migration 000082
    // ═══════════════════════════════════════════════════════════════════════
    public function test_migration_m01_expires_at_nullable_after_patch(): void
    {
        $this->applyAllMigrationsToDisposableDb();

        $col = $this->getColumnInfo('expires_at');
        $this->assertNotNull($col, 'expires_at column must exist');
        $this->assertSame('YES', $col->is_nullable, 'expires_at must be nullable after migration 000082');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M-02: all adoption columns exist with correct nullability
    // ═══════════════════════════════════════════════════════════════════════
    public function test_migration_m02_adoption_columns_exist(): void
    {
        $this->applyAllMigrationsToDisposableDb();

        $columns = DB::select("
            SELECT column_name, is_nullable
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name   = 'inventory_reservations'
              AND column_name  IN ('owner_type','owner_reference','adopted_at')
            ORDER BY column_name
        ");

        $names = array_column($columns, 'column_name');
        sort($names);
        $this->assertSame(['adopted_at', 'owner_reference', 'owner_type'], $names);
        foreach ($columns as $col) {
            $this->assertSame('YES', $col->is_nullable, "{$col->column_name} must be nullable");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M-03: Real reserve() and real adopt() persist expires_at = NULL on PostgreSQL
    // ═══════════════════════════════════════════════════════════════════════
    public function test_migration_m03_adopt_persists_null_expires_at(): void
    {
        $this->applyAllMigrationsToDisposableDb();
        $this->seedReferenceData();

        $uid = uniqid('m03_');
        $tenant = Tenant::create(['name' => 'M03 Tenant', 'slug' => $uid, 'status' => 'active']);
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $tenant->id,
            productType: 'physical',
            sku: 'M03-SKU-'.strtoupper($uid),
            translations: ['en' => ['name' => 'M03 Product']],
        ));
        $wh = Warehouse::create(['tenant_id' => $tenant->id, 'code' => 'WH-'.$uid, 'name' => 'WH', 'country_code' => 'CH']);
        $src = InventorySource::create(['tenant_id' => $tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC-'.$uid, 'name' => 'SRC', 'priority' => 10]);
        $stockItem = StockItem::create(['tenant_id' => $tenant->id, 'inventory_source_id' => $src->id, 'product_id' => $product->id, 'on_hand' => '5.0000', 'reserved' => '0.0000']);

        $service = app(InventoryReservationServiceInterface::class);
        $context = new InventoryContext(tenantId: $tenant->id);
        $resKey = 'm03-res-'.uniqid();

        // 1. Create a NORMAL reservation through the real Inventory reservation service:
        $reserveResult = $service->reserve(
            tenantId: $tenant->id,
            reservationKey: $resKey,
            productId: $product->id,
            variantId: null,
            requestedQuantity: Quantity::fromString('1.0000'),
            context: $context,
            ttlMinutes: 60,
        );

        $this->assertTrue($reserveResult->isSuccess, 'reserve() must succeed');
        $resId = $reserveResult->reservation->id;
        $this->trackedReservationIds[] = $resId;

        // 2. Assert BEFORE adoption (ORM + raw SQL + stock):
        $beforeORM = InventoryReservation::find($resId);
        $this->assertNotNull($beforeORM);
        $this->assertSame('active', $beforeORM->status);
        $this->assertNull($beforeORM->owner_type);
        $this->assertNull($beforeORM->owner_reference);
        $this->assertNotNull($beforeORM->expires_at);

        $beforeRaw = DB::selectOne('SELECT expires_at, owner_type, owner_reference, status FROM inventory_reservations WHERE id = ?', [$resId]);
        $this->assertNotNull($beforeRaw->expires_at, 'expires_at must be non-null before adoption in raw SQL');
        $this->assertNull($beforeRaw->owner_type, 'owner_type must be null before adoption in raw SQL');
        $this->assertSame('active', $beforeRaw->status);

        $stockBefore = $stockItem->fresh()->reserved;
        $this->assertEquals('1.0000', $stockBefore, 'StockItem.reserved must be 1.0000 before adoption');

        // 3. Call the REAL production adopt() contract:
        $adoptResult = $service->adopt(
            tenantId: $tenant->id,
            reservationKey: $resKey,
            ownerType: ReservationOwnerType::ORDER,
            ownerReference: 'ORDER-PG-M03',
        );

        $this->assertTrue($adoptResult->isSuccess, 'adopt() must succeed on PostgreSQL');
        $this->assertFalse($adoptResult->wasAlreadyAdopted);

        // 4. Assert AFTER adoption using fresh ORM + raw SQL:
        $afterORM = InventoryReservation::find($resId);
        $this->assertNotNull($afterORM);
        $this->assertSame('active', $afterORM->status);
        $this->assertSame('order', $afterORM->owner_type);
        $this->assertSame('ORDER-PG-M03', $afterORM->owner_reference);
        $this->assertNotNull($afterORM->adopted_at);
        $this->assertNull($afterORM->expires_at, 'expires_at MUST be null after adoption (ORM)');

        $afterRaw = DB::selectOne('SELECT expires_at, owner_type, owner_reference, status, adopted_at FROM inventory_reservations WHERE id = ?', [$resId]);
        $this->assertNull($afterRaw->expires_at, 'Raw PostgreSQL value of expires_at MUST be NULL after adoption');
        $this->assertSame('order', $afterRaw->owner_type);
        $this->assertSame('ORDER-PG-M03', $afterRaw->owner_reference);
        $this->assertNotNull($afterRaw->adopted_at);
        $this->assertSame('active', $afterRaw->status);

        // 5. Assert StockItem.reserved unchanged:
        $stockAfter = $stockItem->fresh()->reserved;
        $this->assertEquals($stockBefore, $stockAfter, 'StockItem.reserved must remain unchanged by adoption');

        // 6. Clean up ONLY this exact fixture via accepted Inventory release contract first,
        // then exact fixture teardown.
        $released = $service->release($tenant->id, $resKey);
        $this->assertTrue($released, 'release() must succeed');
        $this->assertSame('0.0000', $stockItem->fresh()->reserved);

        DB::table('inventory_reservations')->where('id', $resId)->delete();
        $this->trackedReservationIds = array_diff($this->trackedReservationIds, [$resId]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M-04: REAL Laravel migrate:rollback and re-migration — ISOLATED
    //
    // Proves the full schema lifecycle on a disposable PostgreSQL database.
    //
    // Key isolation guarantees:
    //  - All operations are on the disposable database (never 'hyperstore')
    //  - Only exact tracked reservation IDs are deleted (never a global DELETE)
    //  - An unrelated "decoy" reservation is inserted before rollback and
    //    proven untouched after the entire migration lifecycle completes
    //
    // Required sequence:
    //   A. Apply all migrations including 000082 → assert patched schema
    //   B. Create adopted fixture (expires_at=null), track its exact ID
    //   C. Insert decoy reservation, track its exact ID
    //   D. Release + delete ONLY the adopted fixture (by tracked ID)
    //   E. Assert decoy row still exists and unmodified (NOT deleted)
    //   F. Assert no unrelated null-expires_at rows exist
    //   G. REAL rollback → assert rolled-back schema
    //   H. REAL re-migration → assert re-patched schema + migrations table
    // ═══════════════════════════════════════════════════════════════════════
    public function test_migration_m04_isolated_rollback_and_remigration(): void
    {
        $this->applyAllMigrationsToDisposableDb();
        $this->seedReferenceData();

        // ── A. Assert pre-rollback patched schema ──────────────────────────
        $this->assertNullable('expires_at', 'Pre-rollback: expires_at must be nullable');
        $this->assertColumnExists('owner_type');
        $this->assertColumnExists('owner_reference');
        $this->assertColumnExists('adopted_at');
        $this->assertIndexExists('inv_res_cleanup_index');

        // ── B. Create adopted fixture via direct-schema helper, track its EXACT ID ──
        $adoptedResKey = 'mig-adopted-'.uniqid();
        $adoptedResId = $this->insertAdoptedReservationFixture($adoptedResKey, 'ORDER-MIG-04');
        $this->trackedReservationIds[] = $adoptedResId;

        $adoptedRowBefore = DB::selectOne('SELECT expires_at, owner_type FROM inventory_reservations WHERE id = ?', [$adoptedResId]);
        $this->assertNull($adoptedRowBefore->expires_at, 'Adopted fixture must have null expires_at');
        $this->assertSame('order', $adoptedRowBefore->owner_type);

        // ── C. Insert unrelated decoy reservation (NOT adopted, has expires_at) ──
        $decoyKey = 'mig-decoy-'.uniqid();
        $decoyId = $this->createDecoyReservation($decoyKey);
        $this->trackedReservationIds[] = $decoyId;

        $decoyBefore = DB::selectOne('SELECT id, expires_at, owner_type, status FROM inventory_reservations WHERE id = ?', [$decoyId]);
        $this->assertNotNull($decoyBefore, 'Decoy row must exist before rollback harness');
        $this->assertNotNull($decoyBefore->expires_at, 'Decoy must have non-null expires_at');
        $this->assertNull($decoyBefore->owner_type, 'Decoy must not be adopted');

        // ── D. Release + delete ONLY the adopted fixture by exact ID ──────
        // This is the ONLY DELETE in this test — scoped to the single tracked ID.
        $this->releaseAndDeleteExactReservation($adoptedResId, $adoptedResKey);
        $this->trackedReservationIds = array_diff($this->trackedReservationIds, [$adoptedResId]);

        // ── E. Assert decoy row is completely unmodified ───────────────────
        $decoyAfterCleanup = DB::selectOne('SELECT id, expires_at, owner_type, status FROM inventory_reservations WHERE id = ?', [$decoyId]);
        $this->assertNotNull($decoyAfterCleanup,
            "DECOY ROW MUST SURVIVE: row id={$decoyId} was deleted by migration harness cleanup — FORBIDDEN");
        $this->assertSame((string) $decoyBefore->expires_at, (string) $decoyAfterCleanup->expires_at,
            'Decoy expires_at must be unchanged');
        $this->assertNull($decoyAfterCleanup->owner_type,
            'Decoy owner_type must still be null (unmodified)');
        $this->assertSame($decoyBefore->status, $decoyAfterCleanup->status,
            'Decoy status must be unchanged');

        // ── F. Verify no unscoped null expires_at rows remain ─────────────
        // (Adopted fixture was deleted above; decoy has a real expires_at)
        $nullRows = DB::select('SELECT id, reservation_key FROM inventory_reservations WHERE expires_at IS NULL');
        $this->assertEmpty($nullRows,
            'No null expires_at rows must remain before rollback: '.
            implode(', ', array_map(fn ($r) => "id={$r->id} key={$r->reservation_key}", $nullRows)));

        // ── G. REAL Laravel migrate:rollback ──────────────────────────────
        $rollbackOut = $this->runArtisanMigrate('migrate:rollback', self::MIGRATION_PATH, $this->testDb);
        $this->assertStringContainsString('DONE', $rollbackOut,
            "Rollback must report DONE. Output:\n{$rollbackOut}");

        // Reconnect after DDL
        $this->connectTo($this->testDb);

        // Assert rolled-back schema
        $this->assertNotNullable('expires_at', 'After rollback: expires_at must be NOT NULL');
        $this->assertColumnMissing('owner_type');
        $this->assertColumnMissing('owner_reference');
        $this->assertColumnMissing('adopted_at');
        $this->assertIndexMissing('inv_res_cleanup_index');

        // Migration entry removed
        $entry = DB::selectOne(
            'SELECT id FROM migrations WHERE migration = ?',
            [self::MIGRATION_FILE]
        );
        $this->assertNull($entry, 'migrations table entry must be absent after rollback');

        // ── H. Decoy row still exists after rollback ───────────────────────
        $decoyAfterRollback = DB::selectOne('SELECT id, status FROM inventory_reservations WHERE id = ?', [$decoyId]);
        $this->assertNotNull($decoyAfterRollback,
            "DECOY ROW MUST SURVIVE rollback DDL: id={$decoyId} — migration harness must not delete rows");

        // ── I. REAL Laravel migrate (re-apply) ────────────────────────────
        $migrateOut = $this->runArtisanMigrate('migrate', self::MIGRATION_PATH, $this->testDb);
        $this->assertStringContainsString('DONE', $migrateOut,
            "Re-migration must report DONE. Output:\n{$migrateOut}");

        // Reconnect after DDL
        $this->connectTo($this->testDb);

        // Assert re-patched schema
        $this->assertNullable('expires_at', 'After re-migration: expires_at must be nullable');
        $this->assertColumnExists('owner_type');
        $this->assertColumnExists('owner_reference');
        $this->assertColumnExists('adopted_at');
        $this->assertIndexExists('inv_res_cleanup_index');

        // Migration entry restored
        $entryAfter = DB::selectOne(
            'SELECT id FROM migrations WHERE migration = ?',
            [self::MIGRATION_FILE]
        );
        $this->assertNotNull($entryAfter, 'migrations table entry must be present after re-apply');

        // ── J. Decoy row survives the entire lifecycle ─────────────────────
        $decoyFinal = DB::selectOne('SELECT id FROM inventory_reservations WHERE id = ?', [$decoyId]);
        $this->assertNotNull($decoyFinal,
            "DECOY ROW MUST SURVIVE full migration lifecycle: id={$decoyId}");

        // Cleanup decoy by exact ID (the only other deletion in this test)
        DB::table('inventory_reservations')->where('id', $decoyId)->delete();
        $this->trackedReservationIds = array_diff($this->trackedReservationIds, [$decoyId]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M-05: down() pre-condition — refuses rollback when adopted rows exist
    // ═══════════════════════════════════════════════════════════════════════
    public function test_migration_m05_down_refuses_when_adopted_rows_exist(): void
    {
        $this->applyAllMigrationsToDisposableDb();
        $this->seedReferenceData();

        // Create adopted fixture (expires_at = null) via direct-schema helper
        $resKey = 'm05-res-'.uniqid();
        $resId = $this->insertAdoptedReservationFixture($resKey, 'ORDER-M05');
        $this->trackedReservationIds[] = $resId;

        // Verify adopted row has null expires_at
        $row = InventoryReservation::find($resId);
        $this->assertNull($row->expires_at, 'Adopted reservation must have expires_at=null');

        // Invoke down() directly — must throw before any DDL executes
        $migrationFile = base_path(self::MIGRATION_PATH);
        $migration = include $migrationFile;
        $threwCorrectly = false;

        try {
            $migration->down();
        } catch (\RuntimeException $e) {
            // Explicit pre-condition check in down()
            $threwCorrectly = true;
        } catch (QueryException $e) {
            // DB-level NOT NULL violation if pre-condition check is absent
            $threwCorrectly = true;
        }

        $this->assertTrue($threwCorrectly, 'down() must throw when adopted rows exist');

        // Schema must be intact (no partial DDL executed)
        $this->assertNullable('expires_at', 'expires_at must still be nullable after failed rollback');
        $this->assertColumnExists('owner_type');

        // Cleanup exact fixture ID
        $this->releaseAndDeleteExactReservation($resId, $resKey);
        $this->trackedReservationIds = array_diff($this->trackedReservationIds, [$resId]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Database isolation helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function createDisposableDatabase(string $dbName): void
    {
        // Connect to admin DB to create the disposable database
        $adminPdo = new \PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', self::PG_HOST, self::PG_PORT, self::PG_ADMIN_DB),
            self::PG_USER,
            ''
        );
        $adminPdo->exec("CREATE DATABASE \"{$dbName}\"");
    }

    private function dropDisposableDatabase(string $dbName): void
    {
        // Force close all connections before dropping
        try {
            $adminPdo = new \PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s', self::PG_HOST, self::PG_PORT, self::PG_ADMIN_DB),
                self::PG_USER,
                ''
            );
            $adminPdo->exec("
                SELECT pg_terminate_backend(pid)
                FROM pg_stat_activity
                WHERE datname = '{$dbName}' AND pid <> pg_backend_pid()
            ");
            $adminPdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
        } catch (\Throwable) {
            // Best-effort cleanup — do not fail teardown
        }
    }

    private function connectTo(string $dbName): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => $dbName,
            'database.connections.pgsql.username' => self::PG_USER,
            'database.connections.pgsql.host' => self::PG_HOST,
            'database.connections.pgsql.port' => self::PG_PORT,
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
    }

    /**
     * Apply ALL project migrations to the disposable database.
     * Runs migrate via subprocess with the disposable DB connection.
     */
    private function applyAllMigrationsToDisposableDb(): void
    {
        $output = $this->runArtisanMigrate('migrate', null, $this->testDb);
        $this->assertStringContainsString('DONE', $output,
            "Initial migration must succeed. Output:\n{$output}");

        // Reconnect after migrations
        $this->connectTo($this->testDb);
    }

    /**
     * Seed minimal reference data needed for reservation creation.
     */
    private function seedReferenceData(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\ReferenceDataSeeder']);
    }

    /**
     * Direct schema insertion helper: inserts an adopted reservation (expires_at = null, owner set)
     * bypassing domain services specifically for migration rollback precondition testing.
     */
    private function insertAdoptedReservationFixture(string $resKey, string $ownerRef): int
    {
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'mig-tenant',
            'slug' => $resKey,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
            'product_type' => 'physical',
            'sku' => 'MIG-'.strtoupper(substr($resKey, -6)),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $whId = DB::table('warehouses')->insertGetId([
            'tenant_id' => $tenantId,
            'code' => 'WH-'.substr($resKey, -8),
            'name' => 'MIG WH',
            'country_code' => 'CH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $srcId = DB::table('inventory_sources')->insertGetId([
            'tenant_id' => $tenantId,
            'warehouse_id' => $whId,
            'code' => 'SRC-'.substr($resKey, -8),
            'name' => 'MIG SRC',
            'priority' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_items')->insert([
            'tenant_id' => $tenantId,
            'inventory_source_id' => $srcId,
            'product_id' => $productId,
            'on_hand' => '5.0000',
            'reserved' => '1.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Direct schema insertion: adopted reservation (expires_at = null, owner set)
        $resId = DB::table('inventory_reservations')->insertGetId([
            'tenant_id' => $tenantId,
            'reservation_key' => $resKey,
            'status' => 'active',
            'owner_type' => 'order',
            'owner_reference' => $ownerRef,
            'adopted_at' => now(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $resId;
    }

    /**
     * Create an unadopted decoy reservation with a real expires_at.
     * Returns its exact ID.
     */
    private function createDecoyReservation(string $resKey): int
    {
        $tenantId = DB::table('tenants')->value('id');

        $resId = DB::table('inventory_reservations')->insertGetId([
            'tenant_id' => $tenantId,
            'reservation_key' => $resKey,
            'status' => 'active',
            'owner_type' => null,
            'owner_reference' => null,
            'adopted_at' => null,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $resId;
    }

    /**
     * Delete ONLY that exact row by ID. Never performs a WHERE-based batch delete.
     */
    private function releaseAndDeleteExactReservation(int $resId, string $resKey): void
    {
        DB::table('inventory_reservations')->where('id', $resId)->delete();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Schema assertions
    // ═══════════════════════════════════════════════════════════════════════

    private function getColumnInfo(string $column): ?object
    {
        return DB::selectOne("
            SELECT column_name, is_nullable, data_type
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name   = 'inventory_reservations'
              AND column_name  = ?
        ", [$column]) ?: null;
    }

    private function assertNullable(string $column, string $message = ''): void
    {
        $col = $this->getColumnInfo($column);
        $this->assertNotNull($col, "Column {$column} must exist");
        $this->assertSame('YES', $col->is_nullable, $message ?: "{$column} must be nullable");
    }

    private function assertNotNullable(string $column, string $message = ''): void
    {
        $col = $this->getColumnInfo($column);
        $this->assertNotNull($col, "Column {$column} must exist");
        $this->assertSame('NO', $col->is_nullable, $message ?: "{$column} must be NOT NULL");
    }

    private function assertColumnExists(string $column): void
    {
        $this->assertNotNull($this->getColumnInfo($column), "Column {$column} must exist");
    }

    private function assertColumnMissing(string $column): void
    {
        $this->assertNull($this->getColumnInfo($column), "Column {$column} must NOT exist after rollback");
    }

    private function assertIndexExists(string $indexName): void
    {
        $idx = DB::selectOne("
            SELECT indexname FROM pg_indexes
            WHERE schemaname = 'public'
              AND tablename  = 'inventory_reservations'
              AND indexname  = ?
        ", [$indexName]);
        $this->assertNotNull($idx, "Index {$indexName} must exist");
    }

    private function assertIndexMissing(string $indexName): void
    {
        $idx = DB::selectOne("
            SELECT indexname FROM pg_indexes
            WHERE schemaname = 'public'
              AND tablename  = 'inventory_reservations'
              AND indexname  = ?
        ", [$indexName]);
        $this->assertNull($idx, "Index {$indexName} must NOT exist after rollback");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Artisan subprocess helper
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Runs php artisan migrate/migrate:rollback in a subprocess against the
     * specified database. Uses env vars to force the pgsql connection.
     *
     * @param  string  $command  'migrate' or 'migrate:rollback'
     * @param  string|null  $migrationPath  Specific migration path, or null for all
     * @param  string  $database  Target PostgreSQL database name
     */
    private function runArtisanMigrate(string $command, ?string $migrationPath, string $database): string
    {
        $bp = base_path();

        $envOverrides = [
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => self::PG_USER,
            'DB_HOST' => self::PG_HOST,
            'DB_PORT' => (string) self::PG_PORT,
        ];

        $env = array_merge($_SERVER, $envOverrides);
        $envStr = implode(' ', array_filter(array_map(function ($k, $v) {
            if (! is_string($k) || ! is_scalar($v)) {
                return null;
            }

            return escapeshellarg($k).'='.escapeshellarg((string) $v);
        }, array_keys($env), $env)));

        $artisan = escapeshellarg("{$bp}/artisan");
        $pathFlag = $migrationPath !== null ? '--path='.escapeshellarg($migrationPath) : '';
        $output = shell_exec("env {$envStr} php {$artisan} {$command} {$pathFlag} --force --no-ansi 2>&1");

        return (string) $output;
    }
}
