<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Models\Plugin;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves plugins.plugin_id carries a real, non-nullable PostgreSQL UNIQUE
 * constraint (Owner Delta #2: no uniqueness relying on nullable-column
 * semantics — plugin_id is the only column the constraint needs, since
 * plugin_enablements was removed and enablement is platform-level only).
 */
class PostgreSqlPluginIntegrityTest extends TestCase
{
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
        DB::reconnect('pgsql');

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL engine required for plugin DB integrity tests.');
        }
    }

    public function test_plugin_id_column_is_not_nullable(): void
    {
        $column = DB::selectOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'plugins' AND column_name = 'plugin_id'"
        );

        $this->assertNotNull($column);
        $this->assertSame('NO', $column->is_nullable);
    }

    public function test_plugin_id_has_a_real_unique_constraint_enforced_by_postgres(): void
    {
        $pluginId = 'pg-integrity-test-'.uniqid();

        Plugin::create([
            'plugin_id' => $pluginId,
            'name' => 'PG Integrity Test Plugin',
            'version' => '1.0.0',
            'status' => Plugin::STATUS_INSTALLED,
            'trust_level' => Plugin::TRUST_UNVERIFIED,
            'manifest_snapshot' => ['id' => $pluginId],
        ]);

        try {
            $this->expectException(QueryException::class);

            Plugin::create([
                'plugin_id' => $pluginId,
                'name' => 'Duplicate Attempt',
                'version' => '1.0.0',
                'status' => Plugin::STATUS_INSTALLED,
                'trust_level' => Plugin::TRUST_UNVERIFIED,
                'manifest_snapshot' => ['id' => $pluginId],
            ]);
        } finally {
            DB::table('plugins')->where('plugin_id', $pluginId)->delete();
        }
    }

    public function test_unique_constraint_is_named_and_backed_by_a_real_postgres_index_not_application_logic(): void
    {
        $index = DB::selectOne(
            "SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'plugins' AND indexname = 'uq_plugins_plugin_id'"
        );

        $this->assertNotNull($index, 'Expected a named unique index uq_plugins_plugin_id on the plugins table.');
        $this->assertStringContainsString('UNIQUE', strtoupper($index->indexdef));
    }
}
