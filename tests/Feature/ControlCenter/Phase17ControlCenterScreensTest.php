<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Cms\Livewire\PageEditor;
use Modules\Cms\Livewire\PageManager;
use Modules\Cms\Services\PageBuilderService;
use Modules\Reviews\Livewire\ReviewModerationManager;
use Tests\TestCase;

/**
 * Proves the new Phase-17 Control Center screens render for an authorized
 * user and are permission-gated for an unauthorized one — same shell, same
 * <x-ui.*> components, no custom design.
 */
class Phase17ControlCenterScreensTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $superAdmin;

    private User $plainUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'cc-phase17-tenant', 'name' => 'CC Phase17 Tenant', 'status' => 'active']);
        $this->superAdmin = User::create(['name' => 'Admin', 'email' => 'cc17admin-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);
        $this->plainUser = User::create(['name' => 'Plain', 'email' => 'cc17plain-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
    }

    public function test_review_moderation_renders_for_a_super_admin(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ReviewModerationManager::class)->assertOk();
    }

    public function test_review_moderation_is_denied_to_a_plain_user(): void
    {
        $this->actingAs($this->plainUser);

        Livewire::test(ReviewModerationManager::class)->assertForbidden();
    }

    public function test_cms_page_manager_renders_for_a_super_admin(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(PageManager::class)->assertOk();
    }

    public function test_cms_page_manager_is_denied_to_a_plain_user(): void
    {
        $this->actingAs($this->plainUser);

        Livewire::test(PageManager::class)->assertForbidden();
    }

    public function test_cms_page_editor_renders_and_can_save_a_translation(): void
    {
        $this->actingAs($this->superAdmin);
        $page = app(PageBuilderService::class)->create($this->tenant->id, $this->superAdmin);

        Livewire::test(PageEditor::class, ['page' => $page])
            ->assertOk()
            ->set('title', 'About Us')
            ->set('slug', 'about-us')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('About Us', $page->fresh()->translation('en')->title);
    }
}
