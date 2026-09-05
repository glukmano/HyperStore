<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Order\Models\OrderItem;

/**
 * Owner Delta correction §17: rule-based only (no ML/AI), and only ever
 * learns from qualifying completed Order data — cancelled/unpaid Orders are
 * excluded at the query source. Every result is re-checked against the
 * requesting Store's own ProductStoreListing publication status before
 * being returned, so a product no longer sellable in this Store never
 * appears, however frequently it co-occurred historically.
 */
final class ProductRecommendationService
{
    public function frequentlyBoughtWith(int $tenantId, int $storeId, int $productId, int $limit = 6): Collection
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

        return $this->filterEligibleAndRank($tenantId, $storeId, $coOccurrence, $limit);
    }

    public function relatedByCategory(int $tenantId, int $storeId, Product $product, int $limit = 6): Collection
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

        return $this->filterEligibleAndRank($tenantId, $storeId, $candidateProductIds, $limit);
    }

    public function crossSellUpsell(int $tenantId, int $storeId, Product $product, int $limit = 6): Collection
    {
        return $this->frequentlyBoughtWith($tenantId, $storeId, (int) $product->id, $limit);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $productIdToWeight
     * @return Collection<int, Product>
     */
    private function filterEligibleAndRank(int $tenantId, int $storeId, $productIdToWeight, int $limit): Collection
    {
        if ($productIdToWeight->isEmpty()) {
            return new Collection;
        }

        $eligibleProductIds = ProductStoreListing::where('store_id', $storeId)
            ->where('status', 'published')
            ->whereIn('product_id', $productIdToWeight->keys())
            ->pluck('product_id');

        if ($eligibleProductIds->isEmpty()) {
            return new Collection;
        }

        return Product::where('tenant_id', $tenantId)
            ->whereIn('id', $eligibleProductIds)
            ->get()
            ->sortByDesc(fn (Product $p) => $productIdToWeight[$p->id] ?? 0)
            ->take($limit)
            ->values();
    }
}
