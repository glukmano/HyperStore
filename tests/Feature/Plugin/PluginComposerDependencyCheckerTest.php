<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Exceptions\PluginDependencyConflictException;
use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\Services\PluginComposerDependencyChecker;
use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\PluginTestFixtures;
use Tests\TestCase;

/**
 * Proves PluginComposerDependencyChecker hard-blocks BOTH halves of Owner
 * Delta #3: plugin-vs-plugin AND plugin-vs-platform-root Composer version
 * collisions. The platform root's own vendor/composer/installed.json is
 * read for real (no fake root fixture) — this is a genuine root-vs-plugin
 * conflict, not a simulated one.
 */
class PluginComposerDependencyCheckerTest extends TestCase
{
    use PluginTestFixtures;
    use RefreshDatabase;

    private const string PLUGIN_ID = 'test-fixture-plugin';

    /** A package genuinely present in the real root vendor/composer/installed.json. */
    private const string ROOT_PACKAGE = 'composer/semver';

    protected function setUp(): void
    {
        parent::setUp();
        config(['plugins.allow_unsigned' => true]);
        $this->cleanupLivePluginDirectory();
    }

    protected function tearDown(): void
    {
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

    private function rootPackageVersion(): string
    {
        $data = json_decode((string) file_get_contents(base_path('vendor/composer/installed.json')), associative: true);
        $entries = is_array($data['packages'] ?? null) ? $data['packages'] : $data;
        foreach ((array) $entries as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === self::ROOT_PACKAGE) {
                return (string) $entry['version'];
            }
        }
        $this->fail(self::ROOT_PACKAGE.' must be present in the real root vendor/composer/installed.json for this test to be meaningful.');
    }

    /**
     * Copies the valid-plugin fixture to a temp source dir and overwrites its
     * vendor/composer/installed.json to declare the given package/version —
     * a real installed.json a plugin author's own `composer install` could
     * have produced, not a hand-rolled autoload reimplementation.
     */
    private function sourceWithDeclaredPackage(string $packageName, string $version): string
    {
        $tempSource = sys_get_temp_dir().'/plugin_depcheck_source_'.uniqid();
        File::copyDirectory($this->validPluginSourcePath(), $tempSource);

        File::put($tempSource.'/vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => $packageName, 'version' => $version],
            ],
            'dev' => false,
            'dev-package-names' => [],
        ], JSON_PRETTY_PRINT));

        return $tempSource;
    }

    public function test_find_conflicts_reports_no_conflict_when_a_plugin_declares_the_same_version_as_the_root(): void
    {
        $rootVersion = $this->rootPackageVersion();
        $source = $this->sourceWithDeclaredPackage(self::ROOT_PACKAGE, $rootVersion);

        $checker = app(PluginComposerDependencyChecker::class);
        $conflicts = $checker->findConflicts($source, self::PLUGIN_ID);

        File::deleteDirectory($source);

        $this->assertSame([], $conflicts);
    }

    public function test_find_conflicts_hard_blocks_a_plugin_declaring_an_incompatible_version_of_a_root_provided_package(): void
    {
        $rootVersion = $this->rootPackageVersion();
        $conflictingVersion = $rootVersion === '1.0.0' ? '2.0.0' : '1.0.0';

        $source = $this->sourceWithDeclaredPackage(self::ROOT_PACKAGE, $conflictingVersion);

        $checker = app(PluginComposerDependencyChecker::class);
        $conflicts = $checker->findConflicts($source, self::PLUGIN_ID);

        File::deleteDirectory($source);

        $this->assertCount(1, $conflicts);
        $this->assertSame(self::ROOT_PACKAGE, $conflicts[0]->packageName);
        $this->assertSame(self::PLUGIN_ID, $conflicts[0]->sourceALabel);
        $this->assertSame($conflictingVersion, $conflicts[0]->sourceAVersion);
        $this->assertSame('the platform root', $conflicts[0]->sourceBLabel);
        $this->assertSame($rootVersion, $conflicts[0]->sourceBVersion);
    }

    public function test_enable_hard_blocks_when_the_plugin_conflicts_with_a_root_provided_package_version(): void
    {
        $rootVersion = $this->rootPackageVersion();
        $conflictingVersion = $rootVersion === '1.0.0' ? '2.0.0' : '1.0.0';

        $source = $this->sourceWithDeclaredPackage(self::ROOT_PACKAGE, $conflictingVersion);
        $zip = $this->buildPluginZip([], $source);
        File::deleteDirectory($source);

        $lifecycle = app(PluginLifecycleService::class);
        $plugin = $lifecycle->install($zip);

        try {
            $lifecycle->enable($plugin->plugin_id, approvePermissions: true);
            $this->fail('Expected enable() to hard-block on a root-vs-plugin Composer version conflict.');
        } catch (PluginDependencyConflictException $e) {
            $this->assertStringContainsString(self::ROOT_PACKAGE, $e->getMessage());
            $this->assertStringContainsString('the platform root', $e->getMessage());

            $conflicts = $e->getConflicts();
            $this->assertCount(1, $conflicts);
            $this->assertSame(self::ROOT_PACKAGE, $conflicts[0]->packageName);
        }

        // A hard block on enable must not leave the plugin silently enabled.
        $this->assertNotSame(Plugin::STATUS_ENABLED, $plugin->refresh()->status);
    }

    public function test_find_conflicts_also_still_hard_blocks_plugin_vs_plugin_conflicts(): void
    {
        // Regression guard for the other half of Owner Delta #3, so both
        // halves are proven in the same place going forward.
        $sourceA = $this->sourceWithDeclaredPackage('acme/shared-lib', '1.0.0');
        $sourceB = $this->sourceWithDeclaredPackage('acme/shared-lib', '2.0.0');

        $lifecycle = app(PluginLifecycleService::class);

        $zipA = $this->buildPluginZip(['id' => 'plugin-a', 'namespace' => 'Plugins\\PluginA', 'entrypoint' => 'Plugins\\PluginA\\TestFixturePluginServiceProvider', 'requested_permissions' => ['plugin.plugin-a.view']], $sourceA);
        $pluginA = $lifecycle->install($zipA);
        $lifecycle->enable($pluginA->plugin_id, approvePermissions: true);

        $zipB = $this->buildPluginZip(['id' => 'plugin-b', 'namespace' => 'Plugins\\PluginB', 'entrypoint' => 'Plugins\\PluginB\\TestFixturePluginServiceProvider', 'requested_permissions' => ['plugin.plugin-b.view']], $sourceB);
        $pluginB = $lifecycle->install($zipB);

        File::deleteDirectory($sourceA);
        File::deleteDirectory($sourceB);

        try {
            $lifecycle->enable($pluginB->plugin_id, approvePermissions: true);
            $this->fail('Expected enable() to hard-block on a plugin-vs-plugin Composer version conflict.');
        } catch (PluginDependencyConflictException $e) {
            $this->assertStringContainsString('acme/shared-lib', $e->getMessage());
        } finally {
            File::deleteDirectory(base_path('plugins/plugin-a'));
            File::deleteDirectory(base_path('plugins/plugin-b'));
        }
    }
}
