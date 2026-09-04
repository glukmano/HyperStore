<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\NavigationRegistry;
use App\Core\Plugin\PluginKernel;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PluginTestFixtures;
use Tests\TestCase;

/**
 * Proves the per-request rebuild invariant (ADR-0133): an enabled plugin's
 * boot() contributes real entries to an existing, unmodified registry
 * (NavigationRegistry — reused directly, not duplicated), and a disabled
 * plugin contributes zero entries on the very next PluginKernel cycle,
 * with zero ownership-tracking code added to the registry itself.
 */
class PluginRegistryIntegrationTest extends TestCase
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

    /**
     * Runs a fresh PluginKernel discover/register/boot cycle against a fresh
     * NavigationRegistry instance, simulating exactly one HTTP request's
     * AppServiceProvider::boot() pass — the real per-request rebuild.
     */
    private function bootOneRequestCycle(): NavigationRegistryInterface
    {
        $navigationRegistry = new NavigationRegistry;
        $this->app->instance(NavigationRegistryInterface::class, $navigationRegistry);

        $pluginRegistry = new PluginRegistry;
        $kernel = new PluginKernel(
            app: $this->app,
            registry: $pluginRegistry,
            pluginsBasePath: base_path('plugins'),
            auditManager: $this->app->make(AuditManagerInterface::class),
        );

        $kernel->discover();
        $kernel->registerPlugins();
        $kernel->bootPlugins();

        return $navigationRegistry;
    }

    public function test_an_enabled_plugin_contributes_its_navigation_entry_on_the_next_boot_cycle(): void
    {
        $lifecycle = app(PluginLifecycleService::class);
        $plugin = $lifecycle->install($this->buildPluginZip());
        $lifecycle->enable($plugin->plugin_id, approvePermissions: true);

        $navigation = $this->bootOneRequestCycle();

        $keys = array_map(fn ($item) => $item->key, $navigation->all());
        $this->assertContains('plugin-test-fixture-plugin', $keys);
    }

    public function test_a_disabled_plugin_contributes_zero_navigation_entries_on_the_very_next_boot_cycle(): void
    {
        $lifecycle = app(PluginLifecycleService::class);
        $plugin = $lifecycle->install($this->buildPluginZip());
        $lifecycle->enable($plugin->plugin_id, approvePermissions: true);

        // Prove it's present while enabled.
        $navigationWhileEnabled = $this->bootOneRequestCycle();
        $this->assertContains('plugin-test-fixture-plugin', array_map(fn ($item) => $item->key, $navigationWhileEnabled->all()));

        $lifecycle->disable($plugin->plugin_id);

        // A fresh PluginKernel + fresh NavigationRegistry, exactly as the next
        // real HTTP request would rebuild both from AppServiceProvider::boot().
        $navigationAfterDisable = $this->bootOneRequestCycle();

        $keys = array_map(fn ($item) => $item->key, $navigationAfterDisable->all());
        $this->assertNotContains('plugin-test-fixture-plugin', $keys, 'A disabled plugin must contribute zero registry entries on the next boot cycle.');
    }

    public function test_a_never_installed_plugin_directory_without_a_plugins_row_is_never_loaded(): void
    {
        // Directory exists on disk but there is no corresponding `plugins`
        // row at all (never installed) — discover() must skip it silently,
        // not attempt to autoload/boot an unknown package.
        $sourcePath = $this->validPluginSourcePath();
        $orphanPath = base_path('plugins/'.self::PLUGIN_ID);
        File::copyDirectory($sourcePath, $orphanPath);

        $navigation = $this->bootOneRequestCycle();

        $this->assertSame([], $navigation->all());
    }
}
