<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use App\Core\Stores\Models\Store;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Order\Models\OrderItem;

/**
 * Owner Delta correction §17: rule-based only (no ML/AI), and only ever
 * learns from qualifying completed Order data — cancelled/unpaid Orders are
 * excluded at the query source.
 *
 * Final Completion Delta §7: every result is re-checked, at read time,
 * against the FULL regional-eligibility boundary already established by the
 * Catalog domain model — Store, resolved Market, and the active Store↔Market
 * relation — not merely Store-level publication. There is no separate
 * "regional availability checker" service anywhere else in the codebase to
 * delegate to (confirmed by source audit: even the CMS ProductGrid block's
 * read path only checks Store-level status/visibility); the authoritative
 * eligibility DATA is ProductStoreListing::markets() (pivot is_enabled) and
 * Store::markets() (pivot is_active) — the exact same relations
 * App\Livewire\Storefront\RegionalSwitcher already reads. This service reads
 * those same relations directly rather than inventing a parallel rule.
 */
final class ProductRecommendationService
{
    /**
     * @return Collection<int, Product>
     */
    public function frequentlyBoughtWith(int $tenantId, int $storeId, int $productId, int $limit = 6, ?int $marketId = null): Collection
    {
        $qualifyingOrderIds = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.store_id', $storeId)
            ->where('orders.payment_status', 'paid')
            ->whereNotIn('orders.order_status', ['cancelled'])
            ->where('order_items.product_id', $productId)
            ->pluck('order_items.order_id');

        if ($qualifyingOrderIds->isEmpty()) {
            return new Collection;
        }

        $coOccurrence = OrderItem::query()
            ->select('product_id', DB::raw('COUNT(*) as freq'))
            ->whereIn('order_id', $qualifyingOrderIds)
            ->where('product_id', '!=', $productId)
            ->whereNotNull('product_id')
            ->groupBy('product_id')
            ->orderByDesc('freq')
            ->limit($limit * 4)
            ->pluck('freq', 'product_id');

        return $this->filterEligibleAndRank($tenantId, $storeId, $coOccurrence, $limit, $marketId);
    }

    /**
     * @return Collection<int, Product>
     */
    public function relatedByCategory(int $tenantId, int $storeId, Product $product, int $limit = 6, ?int $marketId = null): Collection
    {
        $categoryIds = $product->categories()->pluck('categories.id');
        if ($categoryIds->isEmpty()) {
            return new Collection;
        }

        $candidateProductIds = Product::where('tenant_id', $tenantId)
            ->where('id', '!=', $product->id)
            ->whereHas('categories', function ($q) use ($categoryIds): void {
                $q->whereIn('categories.id', $categoryIds);
            })
            ->limit($limit * 4)
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => 1]);

        return $this->filterEligibleAndRank($tenantId, $storeId, $candidateProductIds, $limit, $marketId);
    }

    /**
     * @return Collection<int, Product>
     */
    public function crossSellUpsell(int $tenantId, int $storeId, Product $product, int $limit = 6, ?int $marketId = null): Collection
    {
        return $this->frequentlyBoughtWith($tenantId, $storeId, (int) $product->id, $limit, $marketId);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $productIdToWeight
     * @return Collection<int, Product>
     */
    private function filterEligibleAndRank(int $tenantId, int $storeId, $productIdToWeight, int $limit, ?int $marketId): Collection
    {
        if ($productIdToWeight->isEmpty()) {
            return new Collection;
        }

        // A resolved Market that this Store does not actually, actively
        // serve can never make anything eligible — matching the exact
        // relation RegionalSwitcher::mount() already reads
        // ($store->markets()->wherePivot('is_active', true)).
        if ($marketId !== null) {
            $storeServesMarket = Store::where('id', $storeId)
                ->whereHas('markets', function ($q) use ($marketId): void {
                    $q->where('markets.id', $marketId)->where('store_markets.is_active', true);
                })
                ->exists();

            if (! $storeServesMarket) {
                return new Collection;
            }
        }

        $listingQuery = ProductStoreListing::where('store_id', $storeId)
            ->where('status', 'published')
            ->where('visibility', 'visible')
            ->whereIn('product_id', $productIdToWeight->keys());

        if ($marketId !== null) {
            // A listing with no Market rows at all is unrestricted (Catalog
            // only attaches Market rows when marketIds were explicitly
            // supplied at publish time — see PublishProductToStoreAction) —
            // it must not be silently excluded by a Market that was simply
            // never configured for it. A listing that DOES restrict markets
            // must match the resolved Market via an enabled pivot row.
            $listingQuery->where(function ($q) use ($marketId): void {
                $q->whereDoesntHave('markets')
                    ->orWhereHas('markets', function ($q2) use ($marketId): void {
                        $q2->where('markets.id', $marketId)->where('product_store_markets.is_enabled', true);
                    });
            });
        }

        $eligibleProductIds = $listingQuery->pluck('product_id');

        if ($eligibleProductIds->isEmpty()) {
            return new Collection;
        }

        return Product::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('id', $eligibleProductIds)
            ->get()
            ->sortByDesc(fn (Product $p) => $productIdToWeight[$p->id] ?? 0)
            ->take($limit)
            ->values();
    }
}
