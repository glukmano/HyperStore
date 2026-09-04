<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Cms\Services\PageBuilderService;
use Modules\Seo\Exceptions\SubjectNotVisibleException;
use Modules\Seo\Services\SeoMetadataService;
use Tests\TestCase;

/**
 * Proves SeoMetadataService never resolves metadata for unpublished/
 * inactive content — the same authoritative status fields the rest of the
 * platform uses, never a parallel visibility concept.
 */
class SeoMetadataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'seo-tenant', 'name' => 'SEO Tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'seo-store', 'status' => 'active']);
    }

    public function test_metadata_is_resolved_for_a_published_and_visible_product_listing(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SEO-SKU-1', translations: ['en' => ['name' => 'SEO Product']],
        ));
        ProductStoreListing::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'status' => 'published', 'visibility' => 'visible']);

        $metadata = app(SeoMetadataService::class)->forProductAtStore($product, $this->store->id, '/p/SEO-SKU-1');

        $this->assertSame('SEO Product', $metadata->title);
        $this->assertSame('Product', $metadata->jsonLd['@type']);
    }

    public function test_metadata_is_never_resolved_for_a_draft_unpublished_listing(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SEO-SKU-2', translations: ['en' => ['name' => 'Draft Product']],
        ));
        ProductStoreListing::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'status' => 'draft', 'visibility' => 'visible']);

        $this->expectException(SubjectNotVisibleException::class);
        app(SeoMetadataService::class)->forProductAtStore($product, $this->store->id, '/p/SEO-SKU-2');
    }

    public function test_metadata_is_never_resolved_for_a_product_with_no_listing_at_this_store(): void
    {
        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id, productType: 'physical', sku: 'SEO-SKU-3', translations: ['en' => ['name' => 'No Listing Product']],
        ));

        $this->expectException(SubjectNotVisibleException::class);
        app(SeoMetadataService::class)->forProductAtStore($product, $this->store->id, '/p/SEO-SKU-3');
    }

    public function test_metadata_is_never_resolved_for_a_draft_cms_page(): void
    {
        $author = User::create(['name' => 'Author', 'email' => 'seoauthor-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $pageService = app(PageBuilderService::class);
        $page = $pageService->create($this->tenant->id, $author);
        $pageService->setTranslation($page, 'en', 'Draft Page', 'draft-page');

        $this->expectException(SubjectNotVisibleException::class);
        app(SeoMetadataService::class)->forPage($page, '/draft-page', 'en');
    }

    public function test_metadata_is_resolved_for_a_published_cms_page(): void
    {
        $author = User::create(['name' => 'Author', 'email' => 'seoauthor2-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $pageService = app(PageBuilderService::class);
        $page = $pageService->create($this->tenant->id, $author);
        $pageService->setTranslation($page, 'en', 'Published Page', 'published-page');
        $pageService->publish($page, $author);

        $metadata = app(SeoMetadataService::class)->forPage($page, '/published-page', 'en');

        $this->assertSame('Published Page', $metadata->title);
    }

    public function test_hreflang_seam_produces_alternate_urls_for_the_currently_supported_locales_only(): void
    {
        config(['app.supported_locales' => ['en', 'ar'], 'app.fallback_locale' => 'en']);

        $metadata = app(SeoMetadataService::class)->resolveAlternateLocaleUrls(fn (string $locale) => "/{$locale}/about");

        $this->assertSame('/en/about', $metadata['en']);
        $this->assertSame('/ar/about', $metadata['ar']);
        $this->assertSame('/en/about', $metadata['x-default']);
        $this->assertArrayNotHasKey('fr', $metadata);
    }
}
