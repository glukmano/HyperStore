<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\ReferenceData\Models\Language;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Catalog\Livewire\ProductForm;
use Modules\Catalog\Models\Product;
use Modules\Cms\Livewire\BannerManager;
use Modules\Cms\Livewire\BlogManager;
use Modules\Cms\Livewire\FaqManager;
use Modules\Cms\Livewire\MenuManager;
use Modules\Cms\Livewire\PageEditor;
use Modules\Cms\Models\Banner;
use Modules\Cms\Models\BlogPost;
use Modules\Cms\Models\Faq;
use Modules\Cms\Models\Menu;
use Modules\Cms\Models\Page;
use Modules\Cms\Services\PageBuilderService;
use Tests\TestCase;

/**
 * Phase-18 Final Completion Delta §3: adding a new active Locale must make
 * every Catalog/CMS translation editor immediately editable for it, with
 * zero application code change. Proven here against "de" specifically —
 * neither the platform's original en/ar pair — so this cannot pass by
 * accident of a hardcoded two-locale special case.
 */
class DynamicLocaleTranslationEditorsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'dlte-tenant-'.uniqid(), 'name' => 'DLTE Tenant', 'status' => 'active']);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'dlte-store-'.uniqid(), 'status' => 'active']);
        $this->superAdmin = User::create(['name' => 'Admin', 'email' => 'dlteadmin-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
        app(ContextManager::class)->setStore(StoreContext::from($store->id, $store->slug));

        Language::create(['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $this->actingAs($this->superAdmin);
        app()->setLocale('de');
    }

    public function test_blog_manager_saves_the_translation_under_the_current_active_locale(): void
    {
        Livewire::test(BlogManager::class)
            ->set('title', 'Hallo Welt')
            ->set('slug', 'hallo-welt')
            ->set('body', 'Inhalt hier.')
            ->call('create')
            ->assertHasNoErrors();

        $post = BlogPost::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertTrue($post->translations()->where('locale', 'de')->exists());
        $this->assertFalse($post->translations()->where('locale', 'en')->exists());
    }

    public function test_faq_manager_saves_the_translation_under_the_current_active_locale(): void
    {
        Livewire::test(FaqManager::class)
            ->set('question', 'Versand international?')
            ->set('answer', 'Ja, weltweit.')
            ->call('create')
            ->assertHasNoErrors();

        $faq = Faq::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertTrue($faq->translations()->where('locale', 'de')->exists());
    }

    public function test_banner_manager_saves_the_translation_under_the_current_active_locale(): void
    {
        Livewire::test(BannerManager::class)
            ->set('placement', 'homepage_hero')
            ->set('headline', 'Großer Verkauf')
            ->call('create')
            ->assertHasNoErrors();

        $banner = Banner::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertTrue($banner->translations()->where('locale', 'de')->exists());
    }

    public function test_menu_manager_saves_the_item_label_under_the_current_active_locale(): void
    {
        Livewire::test(MenuManager::class)
            ->set('label', 'Über uns')
            ->set('routeType', 'external')
            ->set('routeTarget', '/ueber-uns')
            ->call('addItem')
            ->assertHasNoErrors();

        $menu = Menu::query()->where('tenant_id', $this->tenant->id)->where('key', 'main')->firstOrFail();
        $item = $menu->allItems()->firstOrFail();
        $this->assertTrue($item->translations()->where('locale', 'de')->exists());
    }

    public function test_page_editor_defaults_to_the_current_active_locale_and_can_switch(): void
    {
        $page = app(PageBuilderService::class)->create($this->tenant->id, $this->superAdmin);
        app(PageBuilderService::class)->setTranslation($page, 'en', 'English Title', 'english-title');
        app(PageBuilderService::class)->setTranslation($page, 'de', 'Deutscher Titel', 'deutscher-titel');

        $component = Livewire::test(PageEditor::class, ['page' => $page]);

        // Defaulted to 'de' (the current active Control Center locale).
        $component->assertSet('locale', 'de')->assertSet('title', 'Deutscher Titel');

        // Switching the in-screen selector reloads the OTHER locale's
        // translation without leaving the page.
        $component->set('locale', 'en')->assertSet('title', 'English Title');
    }

    public function test_product_form_saves_the_translation_under_the_current_active_locale(): void
    {
        Livewire::test(ProductForm::class)
            ->set('sku', 'DE-SKU-'.uniqid())
            ->set('name', 'Deutsches Produkt')
            ->set('productType', 'physical')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertTrue($product->translations()->where('locale', 'de')->exists());
    }
}
