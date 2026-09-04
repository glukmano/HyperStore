<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PluginTestFixtures;
use Tests\TestCase;

/**
 * Exercises every plugin:* Artisan command against the real
 * PluginLifecycleService — the same code path the Control Center screens use.
 */
class PluginCliCommandsTest extends TestCase
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

    public function test_plugin_list_reports_no_plugins_when_none_installed(): void
    {
        $this->artisan('plugin:list')
            ->expectsOutputToContain('No plugins installed.')
            ->assertSuccessful();
    }

    public function test_full_cli_lifecycle_install_enable_inspect_disable_uninstall(): void
    {
        $zip = $this->buildPluginZip();

        $this->artisan('plugin:install', ['zip_path' => $zip])
            ->expectsOutputToContain('installed (status: installed')
            ->assertSuccessful();

        $this->assertSame(Plugin::STATUS_INSTALLED, Plugin::query()->where('plugin_id', self::PLUGIN_ID)->firstOrFail()->status);

        $this->artisan('plugin:enable', ['plugin_id' => self::PLUGIN_ID, '--approve-permissions' => true])
            ->expectsOutputToContain('enabled (version 1.0.0)')
            ->assertSuccessful();

        $this->assertSame(Plugin::STATUS_ENABLED, Plugin::query()->where('plugin_id', self::PLUGIN_ID)->firstOrFail()->status);

        $this->artisan('plugin:list')
            ->expectsOutputToContain(self::PLUGIN_ID)
            ->assertSuccessful();

        $this->artisan('plugin:inspect', ['plugin_id' => self::PLUGIN_ID])
            ->expectsOutputToContain('Status: enabled')
            ->assertSuccessful();

        $this->artisan('plugin:doctor')->assertSuccessful();

        $this->artisan('plugin:disable', ['plugin_id' => self::PLUGIN_ID])
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertSame(Plugin::STATUS_DISABLED, Plugin::query()->where('plugin_id', self::PLUGIN_ID)->firstOrFail()->status);

        $this->artisan('plugin:uninstall', ['plugin_id' => self::PLUGIN_ID])
            ->expectsOutputToContain('data preserved')
            ->assertSuccessful();

        $this->assertNull(Plugin::query()->where('plugin_id', self::PLUGIN_ID)->first());
    }

    public function test_inspect_reports_failure_for_unknown_plugin(): void
    {
        $this->artisan('plugin:inspect', ['plugin_id' => 'does-not-exist'])
            ->expectsOutputToContain('is not installed')
            ->assertFailed();
    }

    public function test_enable_without_approve_permissions_fails_and_leaves_plugin_installed(): void
    {
        $this->artisan('plugin:install', ['zip_path' => $this->buildPluginZip()])->assertSuccessful();

        $this->artisan('plugin:enable', ['plugin_id' => self::PLUGIN_ID])
            ->expectsOutputToContain('Enable failed')
            ->assertFailed();

        $this->assertSame(Plugin::STATUS_INSTALLED, Plugin::query()->where('plugin_id', self::PLUGIN_ID)->firstOrFail()->status);
    }

    public function test_uninstall_with_purge_data_prompts_for_confirmation_and_can_be_aborted(): void
    {
        $this->artisan('plugin:install', ['zip_path' => $this->buildPluginZip()])->assertSuccessful();
        $this->artisan('plugin:enable', ['plugin_id' => self::PLUGIN_ID, '--approve-permissions' => true])->assertSuccessful();

        $this->artisan('plugin:uninstall', ['plugin_id' => self::PLUGIN_ID, '--purge-data' => true])
            ->expectsConfirmation('This will permanently delete plugin ['.self::PLUGIN_ID."]'s data via a scoped migration rollback. Continue?", 'no')
            ->expectsOutputToContain('Aborted.')
            ->assertSuccessful();

        // Aborting must leave the plugin fully intact.
        $this->assertNotNull(Plugin::query()->where('plugin_id', self::PLUGIN_ID)->first());
        $this->assertTrue(Schema::hasTable('plugin_test_fixture_data'));
    }

    public function test_install_of_a_zip_missing_a_manifest_fails_cleanly_via_cli(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'no_manifest_').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('src/Foo.php', '<?php');
        $zip->close();

        $this->artisan('plugin:install', ['zip_path' => $zipPath])
            ->expectsOutputToContain('Install failed')
            ->assertFailed();
    }
}
