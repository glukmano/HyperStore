<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Cms\Models\Banner;

class Home extends Component
{
    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        $storeId = app(ContextManager::class)->getStore()->getId();

        $categories = $tenantId !== null
            ? Category::query()->where('tenant_id', $tenantId)->where('status', 'active')->whereNull('parent_id')
                ->with('translations')->orderBy('sort_order')->limit(8)->get()
            : collect();

        $products = ($tenantId !== null && $storeId !== null)
            ? $this->storefrontProducts((int) $tenantId, (int) $storeId)->latest('id')->limit(12)->get()
            : collect();

        $featuredProducts = ($tenantId !== null && $storeId !== null)
            ? $this->storefrontProducts((int) $tenantId, (int) $storeId, onlyFeatured: true)
                ->orderBy('id')->limit(8)->get()
            : collect();

        $heroBanner = $tenantId !== null ? $this->activeBanner((int) $tenantId, 'homepage_hero') : null;
        $promoBanner = $tenantId !== null ? $this->activeBanner((int) $tenantId, 'homepage_promo') : null;

        return view('theme::pages.home', [
            'categories' => $categories,
            'products' => $products,
            'featuredProducts' => $featuredProducts,
            'heroBanner' => $heroBanner,
            'promoBanner' => $promoBanner,
        ])->layout('theme::layouts.app', ['title' => 'Home']);
    }

    /**
     * Only products actually published + visible for THIS store — a
     * product merely "active" at the tenant level but not listed on this
     * store is not a real storefront offering.
     *
     * @return Builder<Product>
     */
    private function storefrontProducts(int $tenantId, int $storeId, bool $onlyFeatured = false)
    {
        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('storeListings', function ($q) use ($storeId, $onlyFeatured): void {
                $q->where('store_id', $storeId)->where('status', 'published')->where('visibility', 'visible');
                if ($onlyFeatured) {
                    $q->where('is_featured', true);
                }
            })
            ->with('translations');
    }

    private function activeBanner(int $tenantId, string $placement): ?Banner
    {
        /** @var Collection<int, Banner> $candidates */
        $candidates = Banner::query()
            ->where('tenant_id', $tenantId)
            ->where('placement', $placement)
            ->where('is_active', true)
            ->with('translations')
            ->orderBy('sort_order')
            ->get();

        return $candidates->first(fn (Banner $banner) => $banner->isCurrentlyActive());
    }
}
