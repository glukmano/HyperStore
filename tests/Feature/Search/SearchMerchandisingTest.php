<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Search\Contracts\SearchServiceInterface;
use Modules\Search\DTOs\SearchQuery;
use Tests\TestCase;

/**
 * Proves a promoted/featured product still must pass every eligibility
 * filter — merchandising can never bypass tenant/store/publish-status
 * checks (a pinned-then-archived product must never appear).
 */
class SearchMerchandisingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_featured_but_unpublished_product_still_never_appears(): void
    {
        $tenant = Tenant::create(['slug' => 'merch-tenant', 'name' => 'Merch Tenant', 'status' => 'active']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'slug' => 'merch-store', 'status' => 'active']);

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $tenant->id, productType: 'physical', sku: 'MERCH-SKU-1', translations: ['en' => ['name' => 'Featured Widget']],
        ));

        // Featured/pinned, but still only in draft — must never surface
        // despite being marked as a merchandising-promoted listing.
        ProductStoreListing::create([
            'product_id' => $product->id, 'store_id' => $store->id,
            'status' => 'draft', 'visibility' => 'visible', 'is_featured' => true,
        ]);

        $this->assertFalse($product->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $tenant->id, storeId: $store->id, channelId: null, term: 'Featured', locale: 'en',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_a_featured_and_published_product_is_marked_featured_in_the_index(): void
    {
        $tenant = Tenant::create(['slug' => 'merch-tenant-2', 'name' => 'Merch Tenant 2', 'status' => 'active']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'slug' => 'merch-store-2', 'status' => 'active']);

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $tenant->id, productType: 'physical', sku: 'MERCH-SKU-2', translations: ['en' => ['name' => 'Promoted Gadget']],
        ));
        ProductStoreListing::create([
            'product_id' => $product->id, 'store_id' => $store->id,
            'status' => 'published', 'visibility' => 'visible', 'is_featured' => true,
        ]);

        $document = $product->toSearchableArray();

        $this->assertTrue($document['is_featured']);
        $this->assertContains($store->id, $document['store_ids']);
    }
}
