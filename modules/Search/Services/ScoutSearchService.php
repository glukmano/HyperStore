<?php

declare(strict_types=1);

namespace Modules\Search\Services;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Search\Contracts\SearchServiceInterface;
use Modules\Search\DTOs\SearchQuery;
use Modules\Search\DTOs\SearchResultSet;

/**
 * The production/default implementation of SearchServiceInterface, wrapping
 * Laravel Scout. Every query is force-scoped to the caller's tenant/store —
 * belt-and-suspenders with the fact that unpublished products are never
 * indexed in the first place (Product::shouldBeSearchable()). The store
 * membership re-check below is intentionally redundant: a real Meilisearch
 * deployment filters "store_ids contains X" server-side via a filterable
 * array attribute, but Scout's driver-agnostic where() does not guarantee
 * that "array contains" semantic on every engine (e.g. the 'collection'
 * driver used in tests) — so it is re-verified in PHP as a
 * belt-and-suspenders safety net, never relied on as the only gate.
 */
final class ScoutSearchService implements SearchServiceInterface
{
    public function search(SearchQuery $query): SearchResultSet
    {
        $builder = Product::search($query->term)
            ->where('tenant_id', $query->tenantId);

        if (isset($query->filters['category_id'])) {
            $builder->where('category_ids', $query->filters['category_id']);
        }

        $paginator = $builder->paginate($query->perPage, 'page', $query->page);

        $this->recordAnalytics($query, $paginator->total());

        $hits = collect($paginator->items())
            ->filter(fn (Product $product) => in_array($query->storeId, $this->storeIdsFor($product), true))
            ->map(function (Product $product) use ($query): array {
                $translation = $product->translation($query->locale);

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $translation !== null ? $translation->name : $product->name,
                ];
            })
            ->values()
            ->all();

        return new SearchResultSet(
            hits: array_values($hits),
            total: count($hits),
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    /**
     * @return list<int>
     */
    private function storeIdsFor(Product $product): array
    {
        return array_values($product->storeListings()->where('store_id', '!=', null)->pluck('store_id')->all());
    }

    private function recordAnalytics(SearchQuery $query, int $resultCount): void
    {
        DB::table('search_queries')->insert([
            'tenant_id' => $query->tenantId,
            'store_id' => $query->storeId,
            'user_id' => null,
            'normalized_query' => mb_strtolower(trim($query->term)),
            'raw_query' => $query->term,
            'result_count' => $resultCount,
            'clicked_product_id' => null,
            'locale' => $query->locale,
            'created_at' => now(),
        ]);
    }
}
