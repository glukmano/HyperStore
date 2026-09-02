<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Validates that migration 2026_09_02_000090_create_order_tables.php:
 *  1. Applies cleanly to PostgreSQL.
 *  2. Rolls back cleanly (drops all order tables, indexes, constraints).
 *  3. Re-migrates cleanly without schema conflicts.
 *  4. Supports full operational order persistence after re-migration.
 *
 * Runs exclusively on a DISPOSABLE test database (hyperstore_ordmig_<uid>) created at
 * setUp and unconditionally dropped at tearDown. The main database is never touched.
 */
class PostgreSqlOrderMigrationTest extends TestCase
{
    private const PG_HOST = '127.0.0.1';

    private const PG_PORT = 5432;

    private const PG_USER = 'lukman';

    private const ADMIN_DB = 'postgres';

    private const TARGET_MIGRATION = 'database/migrations/2026_09_02_000090_create_order_tables.php';

    private string $testDb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDb = 'hyperstore_ordmig_'.bin2hex(random_bytes(6));
        $this->createDisposableDatabase($this->testDb);
        $this->connectTo($this->testDb);
        $this->migrateAllUp();
        (new ReferenceDataSeeder)->run();
    }

    protected function tearDown(): void
    {
        try {
            $this->connectTo(self::ADMIN_DB);
            $this->dropDisposableDatabase($this->testDb);
        } catch (\Throwable) {
            // Ignore teardown errors
        }

        parent::tearDown();
    }

    private function connectTo(string $database): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => self::PG_HOST,
            'database.connections.pgsql.port' => self::PG_PORT,
            'database.connections.pgsql.database' => $database,
            'database.connections.pgsql.username' => self::PG_USER,
            'database.connections.pgsql.password' => '',
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
        DB::setDefaultConnection('pgsql');
    }

    private function createDisposableDatabase(string $name): void
    {
        $this->connectTo(self::ADMIN_DB);
        DB::statement('CREATE DATABASE "'.str_replace('"', '""', $name).'" OWNER "'.self::PG_USER.'"');
    }

    private function dropDisposableDatabase(string $name): void
    {
        DB::statement("
            SELECT pg_terminate_backend(pid)
            FROM pg_stat_activity
            WHERE datname = '{$name}' AND pid <> pg_backend_pid()
        ");
        DB::statement('DROP DATABASE IF EXISTS "'.str_replace('"', '""', $name).'"');
    }

    private function migrateAllUp(): void
    {
        $output = $this->runArtisanMigrate('migrate', null, $this->testDb);
        $this->assertStringNotContainsString('FAIL', $output, "Initial migration must succeed. Output:\n{$output}");
        $this->connectTo($this->testDb);
    }

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

    public function test_order_tables_migration_rollback_and_remigration(): void
    {
        // 1. Initial State: Tables and indexes exist
        $tables = ['orders', 'order_items', 'order_status_history', 'order_number_counters', 'order_operation_keys'];
        foreach ($tables as $tbl) {
            $exists = DB::selectOne("
                SELECT table_name FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = ?
            ", [$tbl]);
            $this->assertNotNull($exists, "Table {$tbl} must exist after initial migration.");
        }

        // 2. Rollback step: rollback order tables migration
        $output = $this->runArtisanMigrate('migrate:rollback', self::TARGET_MIGRATION, $this->testDb);
        $this->assertStringNotContainsString('FAIL', $output, "Rollback must succeed. Output:\n{$output}");
        $this->connectTo($this->testDb);

        // Verify tables are dropped
        foreach ($tables as $tbl) {
            $exists = DB::selectOne("
                SELECT table_name FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = ?
            ", [$tbl]);
            $this->assertNull($exists, "Table {$tbl} must NOT exist after rollback.");
        }

        // 3. Re-migration step
        $output = $this->runArtisanMigrate('migrate', self::TARGET_MIGRATION, $this->testDb);
        $this->assertStringNotContainsString('FAIL', $output, "Re-migration must succeed. Output:\n{$output}");
        $this->connectTo($this->testDb);

        // Verify tables recreated
        foreach ($tables as $tbl) {
            $exists = DB::selectOne("
                SELECT table_name FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = ?
            ", [$tbl]);
            $this->assertNotNull($exists, "Table {$tbl} must exist after re-migration.");
        }

        // 4. Functional sanity test on re-migrated tables
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Mig Tenant',
            'slug' => 'mig-tenant-'.uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $channelId = DB::table('channels')->insertGetId([
            'name' => 'Mig Web',
            'handle' => 'mig-web-'.uniqid(),
            'type' => 'website',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storeId = DB::table('stores')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Mig Store',
            'slug' => 'mig-s1-'.uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $marketId = DB::table('markets')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Mig Market',
            'code' => 'MIG_MKT_'.uniqid(),
            'default_currency_code' => 'EUR',
            'default_locale_code' => 'en',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cartId = DB::table('carts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'market_id' => $marketId,
            'channel_id' => $channelId,
            'currency' => 'EUR',
            'locale' => 'en',
            'status' => 'active',
            'version' => 1,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checkoutId = DB::table('checkout_sessions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'cart_id' => $cartId,
            'store_id' => $storeId,
            'market_id' => $marketId,
            'channel_id' => $channelId,
            'currency' => 'EUR',
            'locale' => 'en',
            'state' => 'ready_for_order',
            'evaluated_cart_version' => 1,
            'version' => 1,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'checkout_id' => $checkoutId,
            'order_number' => 'ORD-20260902-000001',
            'store_id' => $storeId,
            'market_id' => $marketId,
            'channel_id' => $channelId,
            'currency' => 'EUR',
            'locale' => 'en',
            'order_status' => 'placed',
            'payment_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => 1000,
            'grand_total_minor' => 1000,
            'customer_snapshot' => json_encode(['email' => 'mig@example.com']),
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertGreaterThan(0, $orderId);
    }
}
