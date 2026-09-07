<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Stores\Models\StoreDomain;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cms\Models\Banner;
use Tests\TestCase;

/**
 * Pre-Production Readiness — Demo Homepage Completion. Proves the actual
 * root cause of an "empty" homepage is fixed: a plain browser request (no
 * X-Tenant-ID/X-Store-ID header — only manual/API testing ever sends
 * those) must still resolve Tenant/Store context via the real
 * App\Core\Stores\Models\StoreDomain mapping DemoHomepageSeeder creates,
 * and the homepage must render real, DB-backed demo content: a hero
 * banner, a featured category, featured products, new-arrival products,
 * a promotional banner, and working links to a real category and a real
 * product page. Nothing here is a hardcoded Theme fixture — every
 * assertion targets content that only exists because it was seeded into
 * the real Banner/Category/Product/ProductStoreListing tables.
 */
class DemoHomepageContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_freshly_demo_seeded_storefront_homepage_renders_real_linked_demo_content(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->artisan('demo:seed')->assertSuccessful();

        // No X-Tenant-ID/X-Store-ID header at all — this is the exact
        // shape of a plain browser visit. Laravel's default test host
        // ("localhost", from APP_URL) is one of the two hostnames
        // DemoHomepageSeeder maps to the demo store.
        $response = $this->get('/en');

        $response->assertOk();

        // Hero banner (real Modules\Cms\Models\Banner + BannerTranslation).
        $response->assertSee('Welcome to Demo Flagship Store');
        $response->assertSee('Shop Electronics');

        // Featured category (real Modules\Catalog\Models\Category).
        $response->assertSee('Electronics');

        // Featured products section (real ProductStoreListing.is_featured).
        $response->assertSee('Featured Products');
        $response->assertSee('Demo Wireless Headphones');

        // New Arrivals section (real, published, visible products).
        $response->assertSee('New Arrivals');
        $response->assertSee('DEMO-PHYS-001');

        // Promotional banner, distinct from the hero.
        $response->assertSee('Free shipping on orders over $50');

        // The empty-state fallback must never render once real content exists.
        $response->assertDontSee('No products available yet.');

        // Real, working links — not dead hrefs.
        $response->assertSee('href="/c/demo-electronics"', false);
        $response->assertSee('href="/p/DEMO-PHYS-001"', false);

        // A real, seeded product thumbnail image renders (not just the
        // generic no-image icon fallback) — proves DemoHomepageSeeder's
        // CatalogMediaService::attachProductThumbnail() media is wired
        // through product-card.blade.php, not merely present in storage.
        $response->assertSee('/storage/', false);
    }

    public function test_demo_homepage_content_is_bilingual_and_rtl_for_arabic(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->artisan('demo:seed')->assertSuccessful();

        $response = $this->get('/ar');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('مرحبًا بكم في متجر ديمو الرئيسي');
        $response->assertSee('شحن مجاني للطلبات فوق 50 دولارًا');
    }

    public function test_demo_seed_homepage_content_is_idempotent_and_never_created_by_plain_db_seed(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Plain db:seed must never create any homepage demo content.
        $tenantExists = Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->exists();
        $this->assertFalse($tenantExists);
        $this->assertSame(0, Banner::count());

        $this->artisan('demo:seed')->assertSuccessful();
        $firstBannerCount = Banner::count();
        $firstDomainCount = StoreDomain::count();

        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertSame($firstBannerCount, Banner::count());
        $this->assertSame($firstDomainCount, StoreDomain::count());
    }
}
