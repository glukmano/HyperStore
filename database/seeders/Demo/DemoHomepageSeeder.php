<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Core\Stores\Models\Store;
use App\Core\Stores\Models\StoreDomain;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Catalog\Services\CatalogMediaService;
use Modules\Cms\Models\Banner;
use Throwable;

/**
 * Pre-Production Readiness — Demo Homepage Completion. Populates the REAL
 * authoritative structures the storefront homepage (App\Livewire\Storefront\
 * Home) already renders through: a local StoreDomain mapping (so a plain
 * browser visit to the demo store actually resolves Tenant/Store context —
 * the real root cause of an "empty" homepage), the `is_featured` flag on
 * ProductStoreListing, real product_thumbnail media (Modules\Catalog\
 * Services\CatalogMediaService — the same service the Control Center media
 * uploader uses), and two real Modules\Cms\Models\Banner rows (homepage_hero
 * / homepage_promo, each with en+ar BannerTranslation rows and a real
 * image). No demo content is hardcoded into the Theme — every Blade change
 * this stage only renders whatever these real tables contain.
 */
class DemoHomepageSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->firstOrFail();
        $store = Store::where('tenant_id', $tenant->id)->where('slug', 'demo-flagship')->firstOrFail();

        $this->seedLocalDomainMapping($store);
        $this->seedFeaturedProducts($tenant->id, $store->id);
        $this->seedProductThumbnails($tenant->id);
        $this->seedBanners($tenant->id);

        $this->command?->info('Demo homepage content seeded: local domain mapping, featured products, product images, hero + promo banners.');
    }

    /**
     * Owner Delta: the actual root cause of an "empty" homepage is that a
     * plain browser request to a local hostname (no X-Tenant-ID/X-Store-ID
     * header, which only manual/API testing sends) never resolves ANY
     * Tenant/Store context — App\Core\Routing\DomainAddressingService only
     * resolves via a real StoreDomain row. This maps the two hostnames a
     * local `php artisan serve` is commonly reached on.
     */
    private function seedLocalDomainMapping(Store $store): void
    {
        foreach ([
            ['domain' => 'localhost', 'type' => 'primary', 'canonical' => true],
            ['domain' => '127.0.0.1', 'type' => 'custom', 'canonical' => false],
        ] as $mapping) {
            StoreDomain::firstOrCreate(
                ['domain' => $mapping['domain']],
                ['store_id' => $store->id, 'type' => $mapping['type'], 'is_verified' => true, 'canonical' => $mapping['canonical']]
            );
        }
    }

    private function seedFeaturedProducts(int $tenantId, int $storeId): void
    {
        foreach (['DEMO-PHYS-001', 'DEMO-VAR-001'] as $sku) {
            $product = Product::where('tenant_id', $tenantId)->where('sku', $sku)->first();
            if ($product === null) {
                continue;
            }

            ProductStoreListing::where('product_id', $product->id)->where('store_id', $storeId)
                ->update(['is_featured' => true]);
        }
    }

    private function seedProductThumbnails(int $tenantId): void
    {
        $mediaService = app(CatalogMediaService::class);

        $products = Product::where('tenant_id', $tenantId)->get();
        foreach ($products as $product) {
            if ($product->getFirstMedia('product_thumbnail') !== null) {
                continue;
            }

            try {
                $path = $this->generatePlaceholderImage($product->name, $this->colorForProductType($product->product_type));
                $mediaService->attachProductThumbnail($product, $path);
            } catch (Throwable $e) {
                $this->command?->warn("Demo product thumbnail skipped for [{$product->sku}]: ".$e->getMessage());
            } finally {
                if (isset($path) && File::exists($path)) {
                    File::delete($path);
                }
            }
        }
    }

    private function seedBanners(int $tenantId): void
    {
        $category = Category::where('tenant_id', $tenantId)->where('code', 'demo-electronics')->first();
        $featuredProduct = Product::where('tenant_id', $tenantId)->where('sku', 'DEMO-PHYS-001')->first();

        // Relative URLs (absolute: false) — a Banner seeded in a console
        // context has no live request to derive a host from, and a fixed
        // absolute URL baked from config('app.url') would silently break
        // if the app is actually served from a different host/port later.
        $categoryUrl = $category !== null ? route('storefront.category', ['code' => $category->code], false) : null;
        $productUrl = $featuredProduct !== null ? route('storefront.product', ['sku' => $featuredProduct->sku], false) : null;

        $this->seedBanner(
            tenantId: $tenantId,
            placement: 'homepage_hero',
            color: '#2563eb',
            imageLabel: 'Demo Flagship Store',
            translations: [
                'en' => ['headline' => 'Welcome to Demo Flagship Store', 'cta_text' => 'Shop Electronics', 'link_url' => $categoryUrl],
                'ar' => ['headline' => 'مرحبًا بكم في متجر ديمو الرئيسي', 'cta_text' => 'تسوق الإلكترونيات', 'link_url' => $categoryUrl],
            ],
        );

        $this->seedBanner(
            tenantId: $tenantId,
            placement: 'homepage_promo',
            color: '#16a34a',
            imageLabel: 'Free Shipping',
            translations: [
                'en' => ['headline' => 'Free shipping on orders over $50', 'cta_text' => 'Shop Now', 'link_url' => $productUrl],
                'ar' => ['headline' => 'شحن مجاني للطلبات فوق 50 دولارًا', 'cta_text' => 'تسوق الآن', 'link_url' => $productUrl],
            ],
        );
    }

    /**
     * @param  array<string, array{headline: string, cta_text: string, link_url: ?string}>  $translations
     */
    private function seedBanner(int $tenantId, string $placement, string $color, string $imageLabel, array $translations): void
    {
        if (Banner::where('tenant_id', $tenantId)->where('placement', $placement)->exists()) {
            return;
        }

        try {
            $banner = Banner::create([
                'tenant_id' => $tenantId,
                'placement' => $placement,
                'is_active' => true,
                'sort_order' => 0,
            ]);

            foreach ($translations as $locale => $content) {
                $banner->translations()->create([
                    'locale' => $locale,
                    'headline' => $content['headline'],
                    'cta_text' => $content['cta_text'],
                    'link_url' => $content['link_url'],
                ]);
            }

            $path = $this->generatePlaceholderImage($imageLabel, $color);
            $banner->addMedia($path)->toMediaCollection('image');
        } catch (Throwable $e) {
            $this->command?->warn("Demo Banner [{$placement}] seeding skipped: ".$e->getMessage());
        } finally {
            if (isset($path) && File::exists($path)) {
                File::delete($path);
            }
        }
    }

    private function colorForProductType(string $productType): string
    {
        return match ($productType) {
            'physical' => '#0ea5e9',
            'variable' => '#8b5cf6',
            'digital' => '#f59e0b',
            'license' => '#ef4444',
            'gift_card' => '#ec4899',
            'subscription' => '#14b8a6',
            'booking' => '#f97316',
            default => '#64748b',
        };
    }

    /**
     * A small GD-generated placeholder image (solid color + centered
     * label) written to a temp file, deleted immediately after being
     * attached to Spatie MediaLibrary — no network call, no bundled
     * binary asset, no hardcoded demo markup in the Theme.
     */
    private function generatePlaceholderImage(string $label, string $hexColor): string
    {
        $width = 600;
        $height = 600;
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('Failed to allocate placeholder image canvas.');
        }

        $rgb = sscanf($hexColor, '#%02x%02x%02x');
        $r = $this->clampColorByte($rgb[0] ?? null);
        $g = $this->clampColorByte($rgb[1] ?? null);
        $b = $this->clampColorByte($rgb[2] ?? null);

        $bg = imagecolorallocate($image, $r, $g, $b);
        $white = imagecolorallocate($image, 255, 255, 255);
        if ($bg === false || $white === false) {
            throw new \RuntimeException('Failed to allocate placeholder image colors.');
        }

        imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        $font = 5;
        $lines = explode("\n", wordwrap($label, 18, "\n"));
        $lineHeight = imagefontheight($font) + 6;
        $startY = (int) ($height / 2 - (count($lines) * $lineHeight) / 2);
        foreach ($lines as $i => $line) {
            $textWidth = imagefontwidth($font) * strlen($line);
            $x = (int) (($width - $textWidth) / 2);
            imagestring($image, $font, $x, $startY + $i * $lineHeight, $line, $white);
        }

        $path = tempnam(sys_get_temp_dir(), 'demo-placeholder-').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * @return int<0, 255>
     */
    private function clampColorByte(?int $value): int
    {
        return max(0, min(255, $value ?? 0));
    }
}
