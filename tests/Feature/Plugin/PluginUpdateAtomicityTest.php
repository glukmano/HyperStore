<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Contracts\PluginCodeSwapperInterface;
use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\PluginTestFixtures;
use Tests\TestCase;

/**
 * Failure-injection tests for the update atomicity protocol (ADR-0136,
 * Owner Delta #5): migrations succeed against the still-old-code-active
 * schema, but the code swap that publishes the new version fails. Proves
 * the service never leaves the DB schema at the new version while claiming
 * (or leaving) the old version reported as healthy, and that it recovers
 * to the previous working version whenever a safe rollback is possible.
 */
class PluginUpdateAtomicityTest extends TestCase
{
    use PluginTestFixtures;
    use RefreshDatabase;

    private const string PLUGIN_ID = 'test-fixture-plugin';

    protected function setUp(): void
    {
        parent::setUp();
        config(['plugins.allow_unsigned' => true]);
        $this->cleanupLivePluginDirectory();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('plugin_test_fixture_data');
        Schema::dropIfExists('plugin_test_fixture_v2_data');
        $this->cleanupLivePluginDirectory();
        parent::tearDown();
    }

    private function cleanupLivePluginDirectory(): void
    {
        $base = base_path('plugins');
        if (! is_dir($base)) {
            return;
        }
        foreach (glob($base.'/'.self::PLUGIN_ID.'*') ?: [] as $path) {
            File::deleteDirectory($path);
        }
    }

    /**
     * Builds a "v2" package from the same fixture source, adding a second
     * migration so the update path has real new migrations to run.
     */
    private function buildV2Zip(): string
    {
        $sourcePath = $this->validPluginSourcePath();
        $tempSource = sys_get_temp_dir().'/plugin_v2_source_'.uniqid();
        File::copyDirectory($sourcePath, $tempSource);

        File::put(
            $tempSource.'/database/migrations/2024_02_01_000000_create_plugin_test_fixture_v2_data_table.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('plugin_test_fixture_v2_data', function (Blueprint $table): void {
                        $table->id();
                        $table->timestamps();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('plugin_test_fixture_v2_data');
                }
            };
            PHP
        );

        $zip = $this->buildPluginZip(['version' => '2.0.0'], $tempSource);
        File::deleteDirectory($tempSource);

        return $zip;
    }

    /**
     * A fake code swapper: succeeds at moving the live plugin aside to a
     * backup (and, if asked, restoring a backup back to live), but always
     * fails the specific "publish the newly staged package as live" move —
     * deterministically reproducing "migrations succeeded, code swap failed"
     * without relying on platform-specific filesystem failure conditions.
     */
    private function failPublishSwapper(): PluginCodeSwapperInterface
    {
        return new class implements PluginCodeSwapperInterface
        {
            public array $calls = [];

            public function move(string $from, string $to): bool
            {
                $this->calls[] = [$from, $to];

                if (str_contains($from, 'plugin-staging')) {
                    // Simulate the swap failure: destination is left untouched.
                    return false;
                }

                return rename($from, $to);
            }
        };
    }

    public function test_migrate_succeeds_code_swap_fails_recovers_previous_working_version(): void
    {
        $fakeSwapper = $this->failPublishSwapper();
        $this->app->instance(PluginCodeSwapperInterface::class, $fakeSwapper);
        $lifecycle = app(PluginLifecycleService::class);

        $plugin = $lifecycle->install($this->buildPluginZip());
        $plugin = $lifecycle->enable($plugin->plugin_id, approvePermissions: true);
        $this->assertSame('1.0.0', $plugin->version);
        $this->assertTrue(Schema::hasTable('plugin_test_fixture_data'));
        $this->assertFalse(Schema::hasTable('plugin_test_fixture_v2_data'));

        $liveVendorMarker = base_path('plugins/'.self::PLUGIN_ID.'/composer.json');
        $this->assertFileExists($liveVendorMarker);
        $originalContents = file_get_contents($liveVendorMarker);

        try {
            $lifecycle->update(self::PLUGIN_ID, $this->buildV2Zip());
            $this->fail('Expected the update to throw when the code swap fails.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('scoped migration rollback succeeded', $e->getMessage());
        }

        $plugin->refresh();

        // The v2-only migration must have been rolled back — never leave
        // the DB schema at the new version while the old code is what's running.
        $this->assertFalse(Schema::hasTable('plugin_test_fixture_v2_data'));
        $this->assertTrue(Schema::hasTable('plugin_test_fixture_data'));

        // The plugin row must still report the OLD version, not the new one.
        $this->assertSame('1.0.0', $plugin->version);
        $this->assertNotSame(Plugin::STATUS_FAILED, $plugin->status);
        $this->assertNotNull($plugin->failure_reason);

        // The previous code must be restored and live at the original path.
        $this->assertFileExists($liveVendorMarker);
        $this->assertSame($originalContents, file_get_contents($liveVendorMarker));

        // No stray backup or staging directories left behind after recovery.
        $leftoverBackups = glob(base_path('plugins/'.self::PLUGIN_ID.'.rollback-*')) ?: [];
        $this->assertCount(0, $leftoverBackups, 'Recovered backup directory should have been consumed by the restore rename.');

        // Prove the recovered plugin is still fully functional: existing data intact.
        DB::table('plugin_test_fixture_data')->insert(['note' => 'still-alive', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, DB::table('plugin_test_fixture_data')->count());
    }

    /**
     * Builds a "v3" package whose new migration's down() deliberately throws,
     * so the scoped rollback attempt itself fails — exercising the true
     * fail-closed branch of ADR-0136 (rollback also fails).
     */
    private function buildV3ZipWithUnrollbackableMigration(): string
    {
        $sourcePath = $this->validPluginSourcePath();
        $tempSource = sys_get_temp_dir().'/plugin_v3_source_'.uniqid();
        File::copyDirectory($sourcePath, $tempSource);

        File::put(
            $tempSource.'/database/migrations/2024_03_01_000000_create_plugin_test_fixture_v3_data_table.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('plugin_test_fixture_v3_data', function (Blueprint $table): void {
                        $table->id();
                        $table->timestamps();
                    });
                }

                public function down(): void
                {
                    throw new \RuntimeException('Simulated: this migration cannot be rolled back.');
                }
            };
            PHP
        );

        $zip = $this->buildPluginZip(['version' => '3.0.0'], $tempSource);
        File::deleteDirectory($tempSource);

        return $zip;
    }

    public function test_when_rollback_also_fails_the_plugin_fails_closed_and_never_claims_the_old_version_is_healthy(): void
    {
        $fakeSwapper = $this->failPublishSwapper();
        $this->app->instance(PluginCodeSwapperInterface::class, $fakeSwapper);
        $lifecycle = app(PluginLifecycleService::class);

        $plugin = $lifecycle->install($this->buildPluginZip());
        $lifecycle->enable($plugin->plugin_id, approvePermissions: true);

        try {
            $lifecycle->update(self::PLUGIN_ID, $this->buildV3ZipWithUnrollbackableMigration());
            $this->fail('Expected the update to throw when both the code swap and the rollback fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('do not assume the previous version is safe to run', $e->getMessage());
        }

        $reloaded = Plugin::query()->where('plugin_id', self::PLUGIN_ID)->firstOrFail();

        // Fail closed: status must be FAILED, never silently ENABLED on a claim of health.
        $this->assertSame(Plugin::STATUS_FAILED, $reloaded->status);
        $this->assertNotNull($reloaded->failure_reason);
        $this->assertStringContainsString('do not assume the previous version is safe to run', $reloaded->failure_reason);

        // The v3 table exists (its migration ran and could not be rolled back) —
        // schema is now at the new version even though code was not swapped in.
        $this->assertTrue(Schema::hasTable('plugin_test_fixture_v3_data'));
        Schema::dropIfExists('plugin_test_fixture_v3_data');

        // A backup of the working code must be preserved on disk, not discarded.
        $backups = glob(base_path('plugins/'.self::PLUGIN_ID.'.rollback-*')) ?: [];
        $this->assertCount(1, $backups, 'The previous working code must be preserved on disk for manual recovery.');
        $this->assertStringContainsString($backups[0], $reloaded->failure_reason);
    }

    public function test_failed_update_never_leaves_a_disabled_or_missing_plugin_reported_as_the_new_version(): void
    {
        $fakeSwapper = $this->failPublishSwapper();
        $this->app->instance(PluginCodeSwapperInterface::class, $fakeSwapper);
        $lifecycle = app(PluginLifecycleService::class);

        $plugin = $lifecycle->install($this->buildPluginZip());
        $lifecycle->enable($plugin->plugin_id, approvePermissions: true);

        try {
            $lifecycle->update(self::PLUGIN_ID, $this->buildV2Zip());
        } catch (RuntimeException) {
            // expected
        }

        $reloaded = Plugin::query()->where('plugin_id', self::PLUGIN_ID)->firstOrFail();

        $this->assertSame('1.0.0', $reloaded->version, 'A plugin must never report the new version number when the code swap did not actually complete.');
        $this->assertSame(Plugin::STATUS_ENABLED, $reloaded->status, 'A successfully recovered plugin should remain enabled on its previous working version.');
    }
}
