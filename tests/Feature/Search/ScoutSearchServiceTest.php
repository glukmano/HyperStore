<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Search\Contracts\SearchServiceInterface;
use Modules\Search\DTOs\SearchQuery;
use Tests\TestCase;

/**
 * Proves search results respect tenant/store isolation and never surface
 * unpublished products — belt-and-suspenders: unpublished products are
 * never indexed (Product::shouldBeSearchable()) AND every query is
 * force-scoped to the caller's own tenant/store.
 */
class ScoutSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'search-tenant', 'name' => 'Search Tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'search-store', 'status' => 'active']);
    }

    public function test_a_published_and_visible_product_is_findable_by_name(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SEARCH-SKU-1', translations: ['en' => ['name' => 'Blue Wireless Headphones']],
        ));
        ProductStoreListing::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'status' => 'published', 'visibility' => 'visible']);

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Wireless', locale: 'en',
        ));

        $this->assertNotEmpty($result->hits);
        $this->assertSame('SEARCH-SKU-1', $result->hits[0]['sku']);
    }

    public function test_a_draft_product_is_never_indexed_and_never_returned(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SEARCH-DRAFT-1', translations: ['en' => ['name' => 'Draft Wireless Speaker']],
        ));
        ProductStoreListing::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'status' => 'draft', 'visibility' => 'visible']);

        $this->assertFalse($product->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Wireless', locale: 'en',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_a_product_from_a_different_tenant_is_never_returned(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other-search-tenant', 'name' => 'Other Search Tenant', 'status' => 'active']);
        $otherStore = Store::create(['tenant_id' => $otherTenant->id, 'name' => 'Other', 'slug' => 'other-search-store', 'status' => 'active']);

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $otherTenant->id, productType: 'physical', sku: 'SEARCH-CROSSTENANT-1', translations: ['en' => ['name' => 'Cross Tenant Wireless Mouse']],
        ));
        ProductStoreListing::create(['product_id' => $product->id, 'store_id' => $otherStore->id, 'status' => 'published', 'visibility' => 'visible']);

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Wireless', locale: 'en',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_a_product_published_only_at_a_different_store_is_never_returned(): void
    {
        $otherStore = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Other Store', 'slug' => 'search-other-store', 'status' => 'active']);

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SEARCH-OTHERSTORE-1', translations: ['en' => ['name' => 'Other Store Wireless Keyboard']],
        ));
        ProductStoreListing::create(['product_id' => $product->id, 'store_id' => $otherStore->id, 'status' => 'published', 'visibility' => 'visible']);

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Wireless', locale: 'en',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_every_search_is_recorded_for_analytics_including_no_result_queries(): void
    {
        app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'nonexistent-widget', locale: 'en',
        ));

        $row = DB::table('search_queries')->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, $row->result_count);
        $this->assertSame('nonexistent-widget', $row->normalized_query);
    }
}
