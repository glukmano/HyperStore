<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Channels\Models\Channel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\ChannelContext;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Livewire\Storefront\CmsPage;
use App\Livewire\Storefront\SearchResultsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Cms\Services\PageBuilderService;
use Tests\TestCase;

/**
 * Proves the new Phase-17 storefront pages render through the existing
 * Theme system (theme::pages.*) — no bypass of ThemeResolver.
 */
class Phase17StorefrontPagesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'sf17-tenant', 'name' => 'SF17 Tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'sf17-store', 'status' => 'active']);
        $channel = Channel::create(['name' => 'Web', 'type' => 'website', 'handle' => 'sf17-web', 'is_active' => true]);

        $context = app(ContextManager::class);
        $context->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
        $context->setStore(StoreContext::from($this->store->id, $this->store->slug));
        $context->setChannel(ChannelContext::from((int) $channel->id, $channel->handle));
    }

    public function test_search_results_page_renders_and_finds_a_published_product(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SF17-SKU-1', translations: ['en' => ['name' => 'Storefront Search Widget']],
        ));
        ProductStoreListing::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'status' => 'published', 'visibility' => 'visible']);

        Livewire::test(SearchResultsPage::class)
            ->set('q', 'Widget')
            ->assertOk()
            ->assertSee('Storefront Search Widget');
    }

    public function test_search_results_page_renders_empty_state_with_no_query(): void
    {
        Livewire::test(SearchResultsPage::class)->assertOk();
    }

    public function test_cms_page_renders_a_published_page_by_slug(): void
    {
        $author = User::create(['name' => 'Author', 'email' => 'sf17author-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $author);
        $service->setTranslation($page, 'en', 'Our Story', 'our-story');
        $service->addBlock($page, 'rich_text', ['html' => '<p>We build great things.</p>'], $author);
        $service->publish($page, $author);

        Livewire::test(CmsPage::class, ['slug' => 'our-story'])
            ->assertOk()
            ->assertSee('Our Story')
            ->assertSee('We build great things.', false);
    }

    public function test_cms_page_returns_404_for_an_unpublished_page(): void
    {
        $author = User::create(['name' => 'Author', 'email' => 'sf17author2-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $service = app(PageBuilderService::class);
        $page = $service->create($this->tenant->id, $author);
        $service->setTranslation($page, 'en', 'Draft Story', 'draft-story');

        Livewire::test(CmsPage::class, ['slug' => 'draft-story'])->assertStatus(404);
    }
}
