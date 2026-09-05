<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\CategoryTranslation;
use Modules\Cms\Models\BlogPost;
use Modules\Cms\Models\BlogPostTranslation;
use Modules\Cms\Models\Page;
use Modules\Cms\Models\PageTranslation;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Search\Contracts\SearchServiceInterface;
use Modules\Search\DTOs\SearchQuery;
use Tests\TestCase;

/**
 * Phase-17 completion delta §3 — proves Category/Vendor/CMS Page/BlogPost
 * search entities (added alongside the original Product-only indexing),
 * each through the one SearchServiceInterface, each with the same
 * never-indexed-if-ineligible + tenant/store isolation guarantees Product
 * already had.
 */
class SearchEntityCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'entity-search-tenant', 'name' => 'Entity Search Tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'entity-search-store', 'status' => 'active']);
    }

    public function test_an_active_category_assigned_to_the_store_is_findable(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'code' => 'CAT-1', 'status' => 'active', 'sort_order' => 0]);
        CategoryTranslation::create(['category_id' => $category->id, 'locale' => 'en', 'name' => 'Outdoor Gear', 'description' => null, 'slug' => 'outdoor-gear']);
        $category->stores()->attach($this->store->id);

        $this->assertTrue($category->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Outdoor', locale: 'en', entityType: 'category',
        ));

        $this->assertNotEmpty($result->hits);
        $this->assertSame($category->id, $result->hits[0]['id']);
    }

    public function test_a_category_not_assigned_to_any_store_is_never_indexed_or_returned(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'code' => 'CAT-2', 'status' => 'active', 'sort_order' => 0]);
        CategoryTranslation::create(['category_id' => $category->id, 'locale' => 'en', 'name' => 'Unassigned Category', 'description' => null, 'slug' => 'unassigned-category']);

        $this->assertFalse($category->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Unassigned', locale: 'en', entityType: 'category',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_a_category_assigned_only_to_a_different_store_is_never_returned(): void
    {
        $otherStore = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'entity-search-other-store', 'status' => 'active']);

        $category = Category::create(['tenant_id' => $this->tenant->id, 'code' => 'CAT-3', 'status' => 'active', 'sort_order' => 0]);
        CategoryTranslation::create(['category_id' => $category->id, 'locale' => 'en', 'name' => 'Other Store Category', 'description' => null, 'slug' => 'other-store-category']);
        $category->stores()->attach($otherStore->id);

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Other Store', locale: 'en', entityType: 'category',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_a_category_from_a_different_tenant_is_never_returned(): void
    {
        $otherTenant = Tenant::create(['slug' => 'entity-search-other-tenant', 'name' => 'Other Tenant', 'status' => 'active']);
        $otherStore = Store::create(['tenant_id' => $otherTenant->id, 'name' => 'Other', 'slug' => 'other-tenant-store', 'status' => 'active']);

        $category = Category::create(['tenant_id' => $otherTenant->id, 'code' => 'CAT-4', 'status' => 'active', 'sort_order' => 0]);
        CategoryTranslation::create(['category_id' => $category->id, 'locale' => 'en', 'name' => 'Cross Tenant Category', 'description' => null, 'slug' => 'cross-tenant-category']);
        $category->stores()->attach($otherStore->id);

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Cross Tenant', locale: 'en', entityType: 'category',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_an_active_vendor_is_findable(): void
    {
        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Basic Plan', 'code' => 'basic-'.uniqid()]);
        $vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Acme Outdoor Supply',
            'platform_slug' => 'acme-outdoor-'.uniqid(),
            'legal_name' => 'Acme Corp',
            'email' => 'vendor-'.uniqid().'@example.com',
            'payout_currency' => 'USD',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        $this->assertTrue($vendor->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Acme Outdoor', locale: 'en', entityType: 'vendor',
        ));

        $this->assertNotEmpty($result->hits);
        $this->assertSame($vendor->id, $result->hits[0]['id']);
    }

    public function test_a_suspended_vendor_is_never_indexed_or_returned(): void
    {
        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Basic Plan', 'code' => 'basic-'.uniqid()]);
        $vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Suspended Vendor Shop',
            'platform_slug' => 'suspended-vendor-'.uniqid(),
            'legal_name' => 'Suspended Corp',
            'email' => 'vendor-'.uniqid().'@example.com',
            'payout_currency' => 'USD',
            'operational_status' => VendorOperationalStatus::Suspended,
        ]);

        $this->assertFalse($vendor->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Suspended Vendor', locale: 'en', entityType: 'vendor',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_a_pending_approval_vendor_is_never_indexed(): void
    {
        $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'Basic Plan', 'code' => 'basic-'.uniqid()]);
        $vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Pending Vendor Shop',
            'platform_slug' => 'pending-vendor-'.uniqid(),
            'legal_name' => 'Pending Corp',
            'email' => 'vendor-'.uniqid().'@example.com',
            'payout_currency' => 'USD',
            'operational_status' => VendorOperationalStatus::PendingApproval,
        ]);

        $this->assertFalse($vendor->shouldBeSearchable());
    }

    public function test_a_published_cms_page_is_findable(): void
    {
        $page = Page::create(['tenant_id' => $this->tenant->id, 'status' => Page::STATUS_PUBLISHED, 'published_at' => now()->subDay(), 'template' => 'default']);
        PageTranslation::create(['page_id' => $page->id, 'locale' => 'en', 'title' => 'About Our Store', 'slug' => 'about-our-store']);

        $this->assertTrue($page->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'About Our Store', locale: 'en', entityType: 'cms_page',
        ));

        $this->assertNotEmpty($result->hits);
        $this->assertSame($page->id, $result->hits[0]['id']);
    }

    public function test_a_draft_cms_page_is_never_indexed_or_returned(): void
    {
        $page = Page::create(['tenant_id' => $this->tenant->id, 'status' => Page::STATUS_DRAFT, 'template' => 'default']);
        PageTranslation::create(['page_id' => $page->id, 'locale' => 'en', 'title' => 'Draft Page', 'slug' => 'draft-page']);

        $this->assertFalse($page->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Draft Page', locale: 'en', entityType: 'cms_page',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_a_cms_page_from_a_different_tenant_is_never_returned(): void
    {
        $otherTenant = Tenant::create(['slug' => 'entity-search-page-tenant', 'name' => 'Other Page Tenant', 'status' => 'active']);

        $page = Page::create(['tenant_id' => $otherTenant->id, 'status' => Page::STATUS_PUBLISHED, 'published_at' => now()->subDay(), 'template' => 'default']);
        PageTranslation::create(['page_id' => $page->id, 'locale' => 'en', 'title' => 'Cross Tenant Page', 'slug' => 'cross-tenant-page']);

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Cross Tenant Page', locale: 'en', entityType: 'cms_page',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_a_published_blog_post_is_findable(): void
    {
        $post = BlogPost::create(['tenant_id' => $this->tenant->id, 'status' => BlogPost::STATUS_PUBLISHED, 'published_at' => now()->subDay()]);
        BlogPostTranslation::create(['blog_post_id' => $post->id, 'locale' => 'en', 'title' => 'Ten Tips for Camping', 'slug' => 'ten-tips-camping', 'body' => 'Body text.']);

        $this->assertTrue($post->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Camping', locale: 'en', entityType: 'blog_post',
        ));

        $this->assertNotEmpty($result->hits);
        $this->assertSame($post->id, $result->hits[0]['id']);
    }

    public function test_a_draft_blog_post_is_never_indexed_or_returned(): void
    {
        $post = BlogPost::create(['tenant_id' => $this->tenant->id, 'status' => BlogPost::STATUS_DRAFT]);
        BlogPostTranslation::create(['blog_post_id' => $post->id, 'locale' => 'en', 'title' => 'Unpublished Draft Post', 'slug' => 'unpublished-draft-post', 'body' => 'Body text.']);

        $this->assertFalse($post->shouldBeSearchable());

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Unpublished Draft', locale: 'en', entityType: 'blog_post',
        ));

        $this->assertEmpty($result->hits);
    }

    public function test_a_blog_post_from_a_different_tenant_is_never_returned(): void
    {
        $otherTenant = Tenant::create(['slug' => 'entity-search-blog-tenant', 'name' => 'Other Blog Tenant', 'status' => 'active']);

        $post = BlogPost::create(['tenant_id' => $otherTenant->id, 'status' => BlogPost::STATUS_PUBLISHED, 'published_at' => now()->subDay()]);
        BlogPostTranslation::create(['blog_post_id' => $post->id, 'locale' => 'en', 'title' => 'Cross Tenant Blog Post', 'slug' => 'cross-tenant-blog-post', 'body' => 'Body text.']);

        $result = app(SearchServiceInterface::class)->search(new SearchQuery(
            tenantId: $this->tenant->id, storeId: $this->store->id, channelId: null, term: 'Cross Tenant Blog', locale: 'en', entityType: 'blog_post',
        ));

        $this->assertEmpty($result->hits);
    }
}
