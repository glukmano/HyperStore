<?php

declare(strict_types=1);

namespace Modules\Cms\Services;

use App\Core\Context\ContextManager;
use Illuminate\Support\Collection;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;

/**
 * The ProductGrid block's read path — Catalog's own Product/ProductStoreListing
 * models (the normal cross-module read pattern already used throughout this
 * codebase), always filtered to published-and-visible listings for the
 * current store. Never a raw/unscoped query.
 */
final class ProductGridResolver
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @return Collection<int, Product>
     */
    public function resolve(array $config): Collection
    {
        if (! $this->contextManager->hasStore()) {
            return new Collection;
        }

        $storeId = (int) $this->contextManager->getStore()->getId();

        $productIds = ProductStoreListing::query()
            ->where('store_id', $storeId)
            ->where('status', 'published')
            ->where('visibility', 'visible')
            ->when(! empty($config['category_id']), function ($query) use ($config) {
                $query->whereHas('product.categories', fn ($q) => $q->where('categories.id', $config['category_id']));
            })
            ->limit((int) ($config['limit'] ?? 8))
            ->pluck('product_id');

        return Product::query()->whereIn('id', $productIds)->get();
    }
}
