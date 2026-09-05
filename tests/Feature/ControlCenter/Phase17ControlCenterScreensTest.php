<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Catalog\Models\Product;
use Modules\Cms\Livewire\BannerManager;
use Modules\Cms\Livewire\BlogManager;
use Modules\Cms\Livewire\FaqManager;
use Modules\Cms\Livewire\MediaLibraryManager;
use Modules\Cms\Livewire\MenuManager;
use Modules\Cms\Livewire\PageEditor;
use Modules\Cms\Livewire\PageManager;
use Modules\Cms\Livewire\RedirectManager;
use Modules\Cms\Models\Banner;
use Modules\Cms\Models\BlogPost;
use Modules\Cms\Models\Faq;
use Modules\Cms\Models\Menu;
use Modules\Cms\Models\Redirect;
use Modules\Cms\Services\PageBuilderService;
use Modules\Messaging\Livewire\MessagingModerationManager;
use Modules\Reviews\Livewire\QaModerationManager;
use Modules\Reviews\Livewire\ReviewModerationManager;
use Modules\Reviews\Livewire\VendorReviewModerationManager;
use Modules\Search\Livewire\MerchandisingManager;
use Modules\Search\Livewire\SearchAnalyticsDashboard;
use Modules\Search\Livewire\SynonymManager;
use Modules\Search\Models\SearchMerchandisingRule;
use Modules\Search\Models\SearchSynonym;
use Modules\Seo\Livewire\SeoSettingsManager;
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
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'cc-phase17-store', 'status' => 'active']);
        $this->superAdmin = User::create(['name' => 'Admin', 'email' => 'cc17admin-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);
        $this->plainUser = User::create(['name' => 'Plain', 'email' => 'cc17plain-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
        app(ContextManager::class)->setStore(StoreContext::from($store->id, $store->slug));
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

    public function test_page_editor_supports_adding_and_removing_a_block(): void
    {
        $this->actingAs($this->superAdmin);
        $page = app(PageBuilderService::class)->create($this->tenant->id, $this->superAdmin);

        $component = Livewire::test(PageEditor::class, ['page' => $page])
            ->set('newBlockType', 'rich_text')
            ->set('newBlockConfigJson', '{"html":"<p>Hi</p>"}')
            ->call('addBlock')
            ->assertHasNoErrors();

        $block = $page->fresh()->blocks()->firstOrFail();
        $component->call('removeBlock', $block->id);

        $this->assertSame(0, $page->fresh()->blocks()->count());
    }

    public function test_vendor_review_moderation_renders_for_a_super_admin_and_is_denied_to_a_plain_user(): void
    {
        $this->actingAs($this->superAdmin);
        Livewire::test(VendorReviewModerationManager::class)->assertOk();

        $this->actingAs($this->plainUser);
        Livewire::test(VendorReviewModerationManager::class)->assertForbidden();
    }

    public function test_qa_moderation_renders_for_a_super_admin_and_is_denied_to_a_plain_user(): void
    {
        $this->actingAs($this->superAdmin);
        Livewire::test(QaModerationManager::class)->assertOk();

        $this->actingAs($this->plainUser);
        Livewire::test(QaModerationManager::class)->assertForbidden();
    }

    public function test_messaging_moderation_renders_for_a_super_admin_and_is_denied_to_a_plain_user(): void
    {
        $this->actingAs($this->superAdmin);
        Livewire::test(MessagingModerationManager::class)->assertOk();

        $this->actingAs($this->plainUser);
        Livewire::test(MessagingModerationManager::class)->assertForbidden();
    }

    public function test_blog_manager_can_create_and_publish_a_post(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(BlogManager::class)
            ->set('title', 'Hello World')
            ->set('slug', 'hello-world')
            ->set('body', 'Body content here.')
            ->call('create')
            ->assertHasNoErrors();

        $post = BlogPost::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        Livewire::test(BlogManager::class)->call('publish', $post->id);

        $this->assertSame('published', $post->fresh()->status);
    }

    public function test_faq_manager_can_create_and_toggle_publication(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(FaqManager::class)
            ->set('question', 'Do you ship internationally?')
            ->set('answer', 'Yes, worldwide.')
            ->call('create')
            ->assertHasNoErrors();

        $faq = Faq::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertTrue($faq->is_published);
    }

    public function test_menu_manager_can_add_an_item(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(MenuManager::class)
            ->set('label', 'About')
            ->set('routeType', 'external')
            ->set('routeTarget', '/about')
            ->call('addItem')
            ->assertHasNoErrors();

        $menu = Menu::query()->where('tenant_id', $this->tenant->id)->where('key', 'main')->firstOrFail();
        $this->assertSame(1, $menu->allItems()->count());
    }

    public function test_banner_manager_can_create_a_banner(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(BannerManager::class)
            ->set('placement', 'homepage_hero')
            ->set('headline', 'Big Sale')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(1, Banner::query()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_media_library_manager_renders_for_a_super_admin(): void
    {
        $this->actingAs($this->superAdmin);
        Livewire::test(MediaLibraryManager::class)->assertOk();
    }

    public function test_redirect_manager_can_create_a_redirect_and_rejects_a_loop(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(RedirectManager::class)
            ->set('fromPath', '/old-page')
            ->set('toPath', '/new-page')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(1, Redirect::query()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_seo_settings_manager_can_toggle_block_search_engines(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(SeoSettingsManager::class)
            ->set('blockSearchEngines', true)
            ->call('save')
            ->assertHasNoErrors();

        $store = Store::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertTrue((bool) $store->settings['block_search_engines']);
    }

    public function test_synonym_manager_can_create_a_synonym_rule(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(SynonymManager::class)
            ->set('term', 'sofa')
            ->set('synonymsInput', 'couch, settee')
            ->call('create')
            ->assertHasNoErrors();

        $synonym = SearchSynonym::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame(['couch', 'settee'], $synonym->synonyms);
    }

    public function test_merchandising_manager_can_pin_a_product_to_a_query(): void
    {
        $this->actingAs($this->superAdmin);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'CC-MERCH-SKU', 'product_type' => 'physical', 'status' => 'active']);

        Livewire::test(MerchandisingManager::class)
            ->set('queryTerm', 'shoes')
            ->set('sku', 'CC-MERCH-SKU')
            ->set('pinPosition', 1)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(1, SearchMerchandisingRule::query()->where('tenant_id', $this->tenant->id)->where('product_id', $product->id)->count());
    }

    public function test_search_analytics_dashboard_renders_for_a_super_admin_and_is_denied_to_a_plain_user(): void
    {
        $this->actingAs($this->superAdmin);
        Livewire::test(SearchAnalyticsDashboard::class)->assertOk();

        $this->actingAs($this->plainUser);
        Livewire::test(SearchAnalyticsDashboard::class)->assertForbidden();
    }
}
