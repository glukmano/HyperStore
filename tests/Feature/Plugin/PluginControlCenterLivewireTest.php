<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Livewire\PluginDetail;
use App\Core\Plugin\Livewire\PluginList;
use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\Services\PluginLifecycleService;
use App\Models\User;
use Database\Seeders\PluginPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Tests\Support\PluginTestFixtures;
use Tests\TestCase;

/**
 * Proves the Control Center Plugin screens are permission-gated and drive the
 * exact same PluginLifecycleService the CLI uses — no behavior divergence
 * between the two surfaces.
 */
class PluginControlCenterLivewireTest extends TestCase
{
    use PluginTestFixtures;
    use RefreshDatabase;

    private const string PLUGIN_ID = 'test-fixture-plugin';

    protected function setUp(): void
    {
        parent::setUp();
        config(['plugins.allow_unsigned' => true]);
        $this->seed(PluginPermissionSeeder::class);
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

    private function makeUser(bool $superAdmin = false): User
    {
        return User::create([
            'name' => $superAdmin ? 'Super Admin' : 'Plain User',
            'email' => 'plugin-test-'.uniqid().'@hyperstore.test',
            'password' => bcrypt('password'),
            'status' => 'active',
            'is_super_admin' => $superAdmin,
        ]);
    }

    public function test_plugin_list_is_denied_to_a_user_without_the_view_permission(): void
    {
        $this->actingAs($this->makeUser());

        Livewire::test(PluginList::class)->assertForbidden();
    }

    public function test_plugin_list_renders_for_a_super_admin_and_shows_installed_plugins(): void
    {
        $lifecycle = app(PluginLifecycleService::class);
        $lifecycle->install($this->buildPluginZip());

        $this->actingAs($this->makeUser(superAdmin: true));

        Livewire::test(PluginList::class)
            ->assertOk()
            ->assertSee(self::PLUGIN_ID);
    }

    public function test_plugin_detail_is_denied_to_a_user_without_the_view_permission(): void
    {
        $lifecycle = app(PluginLifecycleService::class);
        $lifecycle->install($this->buildPluginZip());

        $this->actingAs($this->makeUser());

        Livewire::test(PluginDetail::class, ['pluginId' => self::PLUGIN_ID])->assertForbidden();
    }

    public function test_enable_action_is_denied_to_a_view_only_user(): void
    {
        $lifecycle = app(PluginLifecycleService::class);
        $lifecycle->install($this->buildPluginZip());

        $viewer = $this->makeUser();
        $viewer->givePermissionTo('plugins.view');
        $this->actingAs($viewer);

        Livewire::test(PluginDetail::class, ['pluginId' => self::PLUGIN_ID])
            ->assertOk()
            ->call('enable')
            ->assertForbidden();
    }

    public function test_super_admin_can_enable_and_disable_a_plugin_through_the_detail_screen(): void
    {
        $lifecycle = app(PluginLifecycleService::class);
        $lifecycle->install($this->buildPluginZip());

        $this->actingAs($this->makeUser(superAdmin: true));

        Livewire::test(PluginDetail::class, ['pluginId' => self::PLUGIN_ID])
            ->call('enable');

        $this->assertSame(Plugin::STATUS_ENABLED, Plugin::query()->where('plugin_id', self::PLUGIN_ID)->firstOrFail()->status);

        Livewire::test(PluginDetail::class, ['pluginId' => self::PLUGIN_ID])
            ->call('disable');

        $this->assertSame(Plugin::STATUS_DISABLED, Plugin::query()->where('plugin_id', self::PLUGIN_ID)->firstOrFail()->status);
    }

    public function test_uninstall_confirmation_flow_requires_opening_the_confirm_dialog_first(): void
    {
        $lifecycle = app(PluginLifecycleService::class);
        $lifecycle->install($this->buildPluginZip());

        $this->actingAs($this->makeUser(superAdmin: true));

        Livewire::test(PluginDetail::class, ['pluginId' => self::PLUGIN_ID])
            ->assertSet('confirmingUninstall', false)
            ->call('openUninstallConfirm')
            ->assertSet('confirmingUninstall', true)
            ->call('cancelUninstallConfirm')
            ->assertSet('confirmingUninstall', false);

        $this->assertNotNull(Plugin::query()->where('plugin_id', self::PLUGIN_ID)->first());
    }

    public function test_install_via_livewire_rejects_a_non_zip_upload(): void
    {
        $this->actingAs($this->makeUser(superAdmin: true));

        $textFile = TemporaryUploadedFile::fake()->create('not-a-plugin.txt', 10);

        Livewire::test(PluginList::class)
            ->set('packageFile', $textFile)
            ->call('installPackage')
            ->assertHasErrors(['packageFile']);
    }
}
