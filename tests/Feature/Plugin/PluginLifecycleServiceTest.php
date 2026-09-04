<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Exceptions\PluginPermissionsNotApprovedException;
use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PluginTestFixtures;
use Tests\TestCase;

/**
 * Proves the full platform-level plugin lifecycle end-to-end against the real
 * filesystem and real migrations: install never auto-enables, disable/plain
 * uninstall preserve plugin-owned data, and only --purge-data removes it.
 */
class PluginLifecycleServiceTest extends TestCase
{
    use PluginTestFixtures;
    use RefreshDatabase;

    private const string PLUGIN_ID = 'test-fixture-plugin';

    private PluginLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plugins.allow_unsigned' => true]);
        $this->lifecycle = app(PluginLifecycleService::class);
        $this->cleanupLivePluginDirectory();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('plugin_test_fixture_data');
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

    public function test_install_never_auto_enables(): void
    {
        $zip = $this->buildPluginZip();

        $plugin = $this->lifecycle->install($zip);

        $this->assertSame(Plugin::STATUS_INSTALLED, $plugin->status);
        $this->assertDirectoryExists(base_path('plugins/'.self::PLUGIN_ID));
        $this->assertFalse(Schema::hasTable('plugin_test_fixture_data'));
    }

    public function test_full_lifecycle_preserves_data_through_disable_and_plain_uninstall_only_purge_removes_it(): void
    {
        $zip = $this->buildPluginZip();

        // install -> enable (runs the fixture's migration, creating a real table)
        $plugin = $this->lifecycle->install($zip);
        $plugin = $this->lifecycle->enable($plugin->plugin_id, approvePermissions: true);

        $this->assertSame(Plugin::STATUS_ENABLED, $plugin->status);
        $this->assertNotNull($plugin->enabled_at);
        $this->assertTrue(Schema::hasTable('plugin_test_fixture_data'));

        DB::table('plugin_test_fixture_data')->insert(['note' => 'survives-disable', 'created_at' => now(), 'updated_at' => now()]);

        // disable -> data untouched
        $plugin = $this->lifecycle->disable($plugin->plugin_id);
        $this->assertSame(Plugin::STATUS_DISABLED, $plugin->status);
        $this->assertNotNull($plugin->disabled_at);
        $this->assertTrue(Schema::hasTable('plugin_test_fixture_data'));
        $this->assertSame(1, DB::table('plugin_test_fixture_data')->count());

        // re-enable -> already-granted capabilities do not require re-approval, migration is a no-op (already applied)
        $plugin = $this->lifecycle->enable($plugin->plugin_id);
        $this->assertSame(Plugin::STATUS_ENABLED, $plugin->status);
        $this->assertSame(1, DB::table('plugin_test_fixture_data')->count());

        $plugin = $this->lifecycle->disable($plugin->plugin_id);

        // plain uninstall (no --purge-data): plugin row + files removed, DB data untouched
        $this->lifecycle->uninstall($plugin->plugin_id, purgeData: false);

        $this->assertNull(Plugin::query()->where('plugin_id', self::PLUGIN_ID)->first());
        $this->assertDirectoryDoesNotExist(base_path('plugins/'.self::PLUGIN_ID));
        $this->assertTrue(Schema::hasTable('plugin_test_fixture_data'), 'Plain uninstall must not remove plugin-owned data.');
        $this->assertSame(1, DB::table('plugin_test_fixture_data')->count());
    }

    public function test_purge_data_uninstall_rolls_back_the_plugins_own_migrations(): void
    {
        $zip = $this->buildPluginZip();

        $plugin = $this->lifecycle->install($zip);
        $plugin = $this->lifecycle->enable($plugin->plugin_id, approvePermissions: true);
        $this->assertTrue(Schema::hasTable('plugin_test_fixture_data'));

        $this->lifecycle->uninstall($plugin->plugin_id, purgeData: true);

        $this->assertNull(Plugin::query()->where('plugin_id', self::PLUGIN_ID)->first());
        $this->assertDirectoryDoesNotExist(base_path('plugins/'.self::PLUGIN_ID));
        $this->assertFalse(Schema::hasTable('plugin_test_fixture_data'), '--purge-data must roll back the plugin-owned migration.');
    }

    public function test_reinstalling_an_already_installed_plugin_id_is_rejected(): void
    {
        $zip = $this->buildPluginZip();
        $this->lifecycle->install($zip);

        $this->expectExceptionMessageMatches('/already installed/');
        $this->lifecycle->install($this->buildPluginZip());
    }

    public function test_enabling_with_newly_requested_capability_requires_explicit_approval(): void
    {
        $zip = $this->buildPluginZip();
        $plugin = $this->lifecycle->install($zip);

        $this->expectException(PluginPermissionsNotApprovedException::class);
        $this->lifecycle->enable($plugin->plugin_id, approvePermissions: false);
    }
}
