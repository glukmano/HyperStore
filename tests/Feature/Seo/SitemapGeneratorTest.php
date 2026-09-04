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
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Seo\Services\SitemapGenerator;
use Tests\TestCase;

/**
 * Proves the sitemap excludes draft/archived Products, unpublished Pages,
 * and suspended Vendors — enforced at generation time via the same
 * authoritative status fields, never filtered only after the fact.
 */
class SitemapGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'sitemap-tenant', 'name' => 'Sitemap Tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'sitemap-store', 'status' => 'active']);
    }

    public function test_sitemap_includes_only_published_and_visible_products(): void
    {
        $visible = app(CreateProductAction::class)->execute(new ProductData(tenantId: $this->tenant->id, productType: 'physical', sku: 'MAP-VISIBLE', translations: ['en' => ['name' => 'Visible']]));
        ProductStoreListing::create(['product_id' => $visible->id, 'store_id' => $this->store->id, 'status' => 'published', 'visibility' => 'visible']);

        $draft = app(CreateProductAction::class)->execute(new ProductData(tenantId: $this->tenant->id, productType: 'physical', sku: 'MAP-DRAFT', translations: ['en' => ['name' => 'Draft']]));
        ProductStoreListing::create(['product_id' => $draft->id, 'store_id' => $this->store->id, 'status' => 'draft', 'visibility' => 'visible']);

        $entries = app(SitemapGenerator::class)->buildEntriesForStore($this->store);
        $locs = array_column($entries, 'loc');

        $this->assertContains('/p/MAP-VISIBLE', $locs);
        $this->assertNotContains('/p/MAP-DRAFT', $locs);
    }

    public function test_sitemap_never_includes_an_unpublished_page(): void
    {
        $author = User::create(['name' => 'Author', 'email' => 'mapauthor-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $pageService = app(PageBuilderService::class);
        $page = $pageService->create($this->tenant->id, $author);
        $pageService->setTranslation($page, 'en', 'Draft Page', 'sitemap-draft-page');

        $entries = app(SitemapGenerator::class)->buildEntriesForStore($this->store);
        $locs = array_column($entries, 'loc');

        $this->assertNotContains('/sitemap-draft-page', $locs);
    }

    public function test_sitemap_never_includes_a_suspended_vendor(): void
    {
        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Basic', 'code' => 'basic']);
        $activeVendor = Vendor::create([
            'tenant_id' => $this->tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Active Vendor',
            'platform_slug' => 'sitemap-active-vendor', 'legal_name' => 'Active Corp', 'email' => 'sitemapactive-'.uniqid().'@test.com', 'payout_currency' => 'USD', 'operational_status' => 'active',
        ]);
        $suspendedVendor = Vendor::create([
            'tenant_id' => $this->tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'Suspended Vendor',
            'platform_slug' => 'sitemap-suspended-vendor', 'legal_name' => 'Suspended Corp', 'email' => 'sitemapsuspended-'.uniqid().'@test.com', 'payout_currency' => 'USD', 'operational_status' => 'suspended',
        ]);

        $entries = app(SitemapGenerator::class)->buildEntriesForStore($this->store);
        $locs = array_column($entries, 'loc');

        $this->assertContains('/vendor/sitemap-active-vendor', $locs);
        $this->assertNotContains('/vendor/sitemap-suspended-vendor', $locs);
    }
}
