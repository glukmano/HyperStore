<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Channels\Models\Channel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\ChannelContext;
use App\Core\Context\DTOs\CurrencyContext;
use App\Core\Context\DTOs\MarketContext;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Livewire\Storefront\CartPage;
use App\Livewire\Storefront\CategoryPage;
use App\Livewire\Storefront\Home;
use App\Livewire\Storefront\ProductPage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Tests\TestCase;

/**
 * Proves the core Storefront happy path (Phase-15 §8/§19) renders and functions against
 * real Cart/Catalog services, composed via Storefront Core (ADR-0132) — no fabricated
 * data, no mocked services. Scope: home -> category -> product -> add-to-cart -> cart.
 * Full checkout-to-order-confirmation requires Shipping rate engine fixtures identical
 * to tests/Feature/Checkout/CheckoutFulfillmentAndShippingTest.php's setup and is
 * exercised there already at the service layer; this test proves the Storefront UI
 * layer that sits in front of it renders and delegates correctly for the segment that
 * does not require a shipping quote.
 */
class StorefrontHappyPathTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Storefront Tenant', 'slug' => 'storefront-tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main Store', 'slug' => 'main-store', 'status' => 'active']);
        $this->market = Market::create([
            'tenant_id' => $this->tenant->id, 'code' => 'US', 'name' => 'United States',
            'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'timezone' => 'America/New_York', 'is_active' => true,
        ]);
        $this->channel = Channel::create(['name' => 'Web', 'type' => 'website', 'handle' => 'storefront-web', 'is_active' => true]);
        $this->store->channels()->attach($this->channel->id, ['is_active' => true, 'is_default' => true]);

        $category = Category::create(['tenant_id' => $this->tenant->id, 'code' => 'SF-CAT', 'status' => 'active', 'sort_order' => 0]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'SF-SKU-1',
            'product_type' => 'physical',
            'status' => 'active',
        ]);
        $this->product->categories()->attach($category->id, ['is_primary' => true]);

        $context = app(ContextManager::class);
        $context->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
        $context->setStore(StoreContext::from($this->store->id, $this->store->slug));
        $context->setMarket(MarketContext::from($this->market->id, $this->market->code));
        $context->setChannel(ChannelContext::from((int) $this->channel->id, $this->channel->handle));
        $context->setCurrency(CurrencyContext::from('USD'));
    }

    public function test_home_page_renders_with_products_and_categories(): void
    {
        Livewire::test(Home::class)
            ->assertOk()
            ->assertSee($this->product->sku);
    }

    public function test_category_page_renders_products_in_that_category(): void
    {
        Livewire::test(CategoryPage::class, ['code' => 'SF-CAT'])
            ->assertOk()
            ->assertSee($this->product->sku);
    }

    public function test_category_page_handles_an_unknown_category_gracefully(): void
    {
        Livewire::test(CategoryPage::class, ['code' => 'NOPE'])
            ->assertOk()
            ->assertSee('could not be found');
    }

    public function test_product_page_renders_and_add_to_cart_creates_a_real_cart_line(): void
    {
        Livewire::test(ProductPage::class, ['sku' => 'SF-SKU-1'])
            ->assertOk()
            ->assertSee($this->product->sku)
            ->set('quantity', 2)
            ->call('addToCart')
            ->assertRedirect(route('storefront.cart'));

        $cart = Cart::query()->where('tenant_id', $this->tenant->id)->first();

        expect($cart)->not->toBeNull();
        expect($cart->lines()->count())->toBe(1);
        expect($cart->lines()->first()->product_id)->toBe($this->product->id);
    }

    public function test_cart_page_displays_the_added_line_and_allows_removal(): void
    {
        Livewire::test(ProductPage::class, ['sku' => 'SF-SKU-1'])
            ->call('addToCart');

        $cart = Cart::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $line = $cart->lines()->firstOrFail();

        Livewire::test(CartPage::class)
            ->assertOk()
            ->assertSee($this->product->sku)
            ->call('removeLine', $line->id);

        expect($cart->lines()->count())->toBe(0);
    }

    public function test_product_page_reports_not_found_for_an_unknown_sku(): void
    {
        Livewire::test(ProductPage::class, ['sku' => 'DOES-NOT-EXIST'])
            ->assertOk()
            ->assertSee('could not be found');
    }
}
