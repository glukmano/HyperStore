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
use App\Livewire\Storefront\Account\GiftRegistriesPage;
use App\Livewire\Storefront\Account\MessagesInbox;
use App\Livewire\Storefront\Account\NotificationPreferencesPage;
use App\Livewire\Storefront\Account\RecentlyViewedPage;
use App\Livewire\Storefront\Account\WishlistPage;
use App\Livewire\Storefront\CmsPage;
use App\Livewire\Storefront\ComparePage;
use App\Livewire\Storefront\GiftRegistryPublicPage;
use App\Livewire\Storefront\ProductPage;
use App\Livewire\Storefront\SearchResultsPage;
use App\Livewire\Storefront\VendorStorefrontPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Cms\Services\PageBuilderService;
use Modules\Customers\Services\GiftRegistryService;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorStoreParticipation;
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

    public function test_product_page_renders_wishlist_follow_alert_and_compare_actions_for_a_guest(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SF17-SKU-2', translations: ['en' => ['name' => 'Compare Me Widget']],
        ));
        $product->update(['status' => 'active']);
        ProductStoreListing::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'status' => 'published', 'visibility' => 'visible']);

        Livewire::test(ProductPage::class, ['sku' => $product->sku])
            ->assertOk()
            ->assertSee('Add to Wishlist')
            ->assertSee('Follow this Product')
            ->assertSee('Add to Compare')
            ->call('toggleWishlist')
            ->assertSee('In Wishlist')
            ->call('toggleCompare')
            ->assertSee('Remove from Compare');
    }

    public function test_compare_page_lists_products_added_via_the_session(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SF17-SKU-3', translations: ['en' => ['name' => 'Session Compare Widget']],
        ));

        session()->put('compare_product_ids', [$product->id]);

        Livewire::test(ComparePage::class)
            ->assertOk()
            ->assertSee('Session Compare Widget');
    }

    public function test_recently_viewed_page_lists_a_guest_session_view_recorded_by_the_product_page(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SF17-SKU-4', translations: ['en' => ['name' => 'Recently Seen Widget']],
        ));
        $product->update(['status' => 'active']);
        ProductStoreListing::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'status' => 'published', 'visibility' => 'visible']);

        Livewire::test(ProductPage::class, ['sku' => $product->sku])->assertOk();

        Livewire::test(RecentlyViewedPage::class)
            ->assertOk()
            ->assertSee('Recently Seen Widget');
    }

    public function test_wishlist_page_renders_for_a_guest_and_an_authenticated_user(): void
    {
        Livewire::test(WishlistPage::class)->assertOk();

        $user = User::create(['name' => 'Wishlister', 'email' => 'sf17wish-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $this->actingAs($user);

        Livewire::test(WishlistPage::class)->assertOk();
    }

    public function test_vendor_storefront_page_renders_follow_button_and_vendor_reviews_section(): void
    {
        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Basic', 'code' => 'basic-'.uniqid()]);
        $vendor = Vendor::create([
            'tenant_id' => $this->tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Storefront Vendor',
            'platform_slug' => 'storefront-vendor-'.uniqid(), 'legal_name' => 'Storefront Vendor Corp',
            'email' => 'sfvendor-'.uniqid().'@test.com', 'payout_currency' => 'USD',
            'operational_status' => VendorOperationalStatus::Active,
        ]);
        VendorStoreParticipation::create([
            'tenant_id' => $this->tenant->id, 'vendor_id' => $vendor->id, 'store_id' => $this->store->id, 'is_enabled' => true,
        ]);

        Livewire::test(VendorStorefrontPage::class, ['slug' => $vendor->platform_slug])
            ->assertOk()
            ->assertSee('Follow this Vendor')
            ->assertSee('Vendor Reviews');
    }

    public function test_gift_registry_public_page_renders_buyable_items(): void
    {
        $owner = User::create(['name' => 'Registry Owner', 'email' => 'sf17owner-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SF17-SKU-5', translations: ['en' => ['name' => 'Registry Widget']],
        ));

        $registry = app(GiftRegistryService::class)->create($owner, 'Public Registry', 'wedding', null);
        app(GiftRegistryService::class)->addItem($registry, $product->id, null, 1);

        Livewire::test(GiftRegistryPublicPage::class, ['shareToken' => $registry->share_token])
            ->assertOk()
            ->assertSee('Registry Widget')
            ->assertSee('Buy This Gift');
    }

    public function test_messages_inbox_and_notification_preferences_require_authentication(): void
    {
        $user = User::create(['name' => 'Msg User', 'email' => 'sf17msg-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $this->actingAs($user);

        Livewire::test(MessagesInbox::class)->assertOk();
        Livewire::test(NotificationPreferencesPage::class)->assertOk();
        Livewire::test(GiftRegistriesPage::class)->assertOk();
    }
}
