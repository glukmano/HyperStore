<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperCenter;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ScaffoldMakeCommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/test-scaffold-theme'));
        File::deleteDirectory(base_path('plugins/test-scaffold-plugin'));
        parent::tearDown();
    }

    public function test_theme_make_scaffolds_a_valid_minimal_theme(): void
    {
        $this->artisan('theme:make', ['name' => 'test-scaffold-theme'])->assertSuccessful();

        $this->assertFileExists(base_path('themes/test-scaffold-theme/theme.json'));
        $this->assertFileExists(base_path('themes/test-scaffold-theme/layouts/app.blade.php'));
        $this->assertFileExists(base_path('themes/test-scaffold-theme/pages/home.blade.php'));

        $manifest = json_decode((string) file_get_contents(base_path('themes/test-scaffold-theme/theme.json')), true);
        $this->assertSame('test-scaffold-theme', $manifest['name']);
        $this->assertSame('default', $manifest['extends']);
    }

    public function test_theme_make_refuses_to_overwrite_an_existing_theme(): void
    {
        $this->artisan('theme:make', ['name' => 'test-scaffold-theme'])->assertSuccessful();
        $this->artisan('theme:make', ['name' => 'test-scaffold-theme'])->assertFailed();
    }

    public function test_plugin_make_scaffolds_a_valid_minimal_plugin(): void
    {
        $this->artisan('plugin:make', ['id' => 'test-scaffold-plugin'])->assertSuccessful();

        $this->assertFileExists(base_path('plugins/test-scaffold-plugin/plugin.json'));
        $this->assertFileExists(base_path('plugins/test-scaffold-plugin/composer.json'));
        $this->assertFileExists(base_path('plugins/test-scaffold-plugin/src/TestScaffoldPluginServiceProvider.php'));

        $manifest = json_decode((string) file_get_contents(base_path('plugins/test-scaffold-plugin/plugin.json')), true);
        $this->assertSame('test-scaffold-plugin', $manifest['id']);
        $this->assertSame('Plugins\\TestScaffoldPlugin\\TestScaffoldPluginServiceProvider', $manifest['entrypoint']);
    }

    public function test_plugin_make_refuses_to_overwrite_an_existing_plugin(): void
    {
        $this->artisan('plugin:make', ['id' => 'test-scaffold-plugin'])->assertSuccessful();
        $this->artisan('plugin:make', ['id' => 'test-scaffold-plugin'])->assertFailed();
    }
}
