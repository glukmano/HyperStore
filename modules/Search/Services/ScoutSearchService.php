<?php

declare(strict_types=1);

namespace Modules\Search\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Cms\Models\BlogPost;
use Modules\Cms\Models\Page;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Models\Vendor;
use Modules\Search\Contracts\SearchServiceInterface;
use Modules\Search\DTOs\SearchQuery;
use Modules\Search\DTOs\SearchResultSet;

/**
 * The production/default implementation of SearchServiceInterface, wrapping
 * Laravel Scout across five indexed entities (product, category, vendor,
 * cms_page, blog_post). Every query is force-scoped to the caller's
 * tenant/store context — belt-and-suspenders with the fact that
 * unpublished/inactive/suspended records are never indexed in the first
 * place (each model's own shouldBeSearchable()).
 *
 * Stale-hit safety is a single batched revalidation query per search() call
 * (one IN(...) against the authoritative Postgres table), never a per-hit
 * N+1 lookup — a real Meilisearch deployment can lag behind a Postgres
 * change, so this re-checks the SAME authoritative status/visibility field
 * the rest of the platform uses, not just store membership.
 */
final class ScoutSearchService implements SearchServiceInterface
{
    public function search(SearchQuery $query): SearchResultSet
    {
        [$hits, $total] = match ($query->entityType) {
            'category' => $this->searchCategories($query),
            'vendor' => $this->searchVendors($query),
            'cms_page' => $this->searchPages($query),
            'blog_post' => $this->searchBlogPosts($query),
            default => $this->searchProducts($query),
        };

        $searchQueryId = $this->recordAnalytics($query, $total);

        return new SearchResultSet(
            hits: $hits,
            total: $total,
            page: $query->page,
            perPage: $query->perPage,
            searchQueryId: $searchQueryId,
        );
    }

    public function recordClick(int $searchQueryId, int $tenantId, int $productId, ?int $resultPosition = null): void
    {
        DB::table('search_clicks')->insert([
            'search_query_id' => $searchQueryId,
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'result_position' => $resultPosition,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchProducts(SearchQuery $query): array
    {
        $builder = Product::search($query->term)
            ->where('tenant_id', $query->tenantId)
            ->options(['attributesToSearchOn' => [
                'sku',
                ...$this->localeScopedAttributes($query->locale, ['name', 'description']),
            ]]);

        $paginator = $builder->paginate($query->perPage, 'page', $query->page);
        $products = collect($paginator->items());

        if ($products->isEmpty()) {
            return [[], 0];
        }

        $ids = $products->pluck('id')->all();

        // Batch revalidation: the SAME authoritative status/visibility check
        // Catalog itself uses, re-verified for every returned id in one query.
        $validIds = DB::table('product_store_listings')
            ->whereIn('product_id', $ids)
            ->where('store_id', $query->storeId)
            ->where('status', 'published')
            ->where('visibility', 'visible')
            ->pluck('product_id')
            ->flip();

        $pinnedIds = $this->pinnedProductIds($query);

        $hits = $products
            ->filter(fn (Product $product) => $validIds->has($product->id))
            ->map(function (Product $product) use ($query, $pinnedIds): array {
                $translation = $product->translation($query->locale);

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $translation !== null ? $translation->name : $product->name,
                    'is_pinned' => $pinnedIds->contains($product->id),
                ];
            })
            ->sortByDesc('is_pinned')
            ->values()
            ->all();

        return [array_values($hits), count($hits)];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchCategories(SearchQuery $query): array
    {
        $paginator = Category::search($query->term)
            ->where('tenant_id', $query->tenantId)
            ->options(['attributesToSearchOn' => $this->localeScopedAttributes($query->locale, ['name'])])
            ->paginate($query->perPage, 'page', $query->page);
        $categories = collect($paginator->items());

        if ($categories->isEmpty()) {
            return [[], 0];
        }

        $validIds = DB::table('category_stores')
            ->whereIn('category_id', $categories->pluck('id')->all())
            ->where('store_id', $query->storeId)
            ->pluck('category_id')
            ->flip();

        $hits = $categories
            ->filter(fn (Category $category) => $validIds->has($category->id))
            ->map(function (Category $category) use ($query): array {
                $translation = $category->translation($query->locale);

                return [
                    'id' => $category->id,
                    'name' => $translation !== null ? $translation->name : $category->getNameAttribute(),
                ];
            })
            ->values()
            ->all();

        return [array_values($hits), count($hits)];
    }

    /**
     * Vendor storefronts are tenant-wide (not per-store), so no store
     * membership check is needed here — only operational-status
     * revalidation, matching Vendor::shouldBeSearchable().
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchVendors(SearchQuery $query): array
    {
        $paginator = Vendor::search($query->term)->where('tenant_id', $query->tenantId)->paginate($query->perPage, 'page', $query->page);
        $vendors = collect($paginator->items());

        if ($vendors->isEmpty()) {
            return [[], 0];
        }

        $activeIds = DB::table('vendors')
            ->whereIn('id', $vendors->pluck('id')->all())
            ->where('operational_status', VendorOperationalStatus::Active->value)
            ->pluck('id')
            ->flip();

        $hits = $vendors
            ->filter(fn (Vendor $vendor) => $activeIds->has($vendor->id))
            ->map(fn (Vendor $vendor) => ['id' => $vendor->id, 'name' => $vendor->name, 'platform_slug' => $vendor->platform_slug])
            ->values()
            ->all();

        return [array_values($hits), count($hits)];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchPages(SearchQuery $query): array
    {
        $paginator = Page::search($query->term)
            ->where('tenant_id', $query->tenantId)
            ->options(['attributesToSearchOn' => $this->localeScopedAttributes($query->locale, ['title', 'slug'])])
            ->paginate($query->perPage, 'page', $query->page);
        $pages = collect($paginator->items());

        if ($pages->isEmpty()) {
            return [[], 0];
        }

        $publishedIds = DB::table('pages')
            ->whereIn('id', $pages->pluck('id')->all())
            ->where('status', Page::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->pluck('id')
            ->flip();

        $hits = $pages
            ->filter(fn (Page $page) => $publishedIds->has($page->id))
            ->map(function (Page $page) use ($query): array {
                $translation = $page->translation($query->locale);

                return ['id' => $page->id, 'title' => $translation?->title, 'slug' => $translation?->slug];
            })
            ->values()
            ->all();

        return [array_values($hits), count($hits)];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchBlogPosts(SearchQuery $query): array
    {
        $paginator = BlogPost::search($query->term)
            ->where('tenant_id', $query->tenantId)
            ->options(['attributesToSearchOn' => $this->localeScopedAttributes($query->locale, ['title', 'excerpt', 'slug'])])
            ->paginate($query->perPage, 'page', $query->page);
        $posts = collect($paginator->items());

        if ($posts->isEmpty()) {
            return [[], 0];
        }

        $publishedIds = DB::table('blog_posts')
            ->whereIn('id', $posts->pluck('id')->all())
            ->where('status', BlogPost::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->pluck('id')
            ->flip();

        $hits = $posts
            ->filter(fn (BlogPost $post) => $publishedIds->has($post->id))
            ->map(function (BlogPost $post) use ($query): array {
                $translation = $post->translation($query->locale);

                return ['id' => $post->id, 'title' => $translation?->title, 'slug' => $translation?->slug];
            })
            ->values()
            ->all();

        return [array_values($hits), count($hits)];
    }

    /**
     * PostgreSQL-authoritative pinned-product ids for this query term/store —
     * re-validated against live eligibility by the caller (a pinned-then-
     * archived product is already excluded upstream by $validIds), never a
     * separate unfiltered merchandising path.
     *
     * @return Collection<int, int>
     */
    private function pinnedProductIds(SearchQuery $query): Collection
    {
        return DB::table('search_merchandising_rules')
            ->where('tenant_id', $query->tenantId)
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('store_id', $query->storeId)->orWhereNull('store_id');
            })
            ->where(function ($q) use ($query) {
                $q->whereRaw('LOWER(query_term) = ?', [mb_strtolower(trim($query->term))])->orWhereNull('query_term');
            })
            ->orderBy('pin_position')
            ->pluck('product_id');
    }

    /**
     * Phase-18 Owner Delta §12: a search in one Locale must only rank on
     * that Locale's own fields (plus the requested-locale prefix always
     * takes priority over the fallback prefix, in deterministic order) —
     * never every registered Locale's fields equally, which would let an
     * unrelated-language translation leak relevance/outrank the correct
     * one. The active-locale sync service (Meilisearch attribute
     * registration) still declares every existing Locale's fields on the
     * index; this is what scopes any single QUERY EXECUTION down to just
     * the ones that matter for that request.
     *
     * @param  list<string>  $fieldPrefixes
     * @return list<string>
     */
    private function localeScopedAttributes(string $locale, array $fieldPrefixes): array
    {
        $fallback = (string) config('app.fallback_locale', 'en');
        $locales = $locale === $fallback ? [$locale] : [$locale, $fallback];

        $attributes = [];
        foreach ($locales as $localeCode) {
            foreach ($fieldPrefixes as $prefix) {
                $attributes[] = $prefix.'_'.$localeCode;
            }
        }

        return $attributes;
    }

    private function recordAnalytics(SearchQuery $query, int $resultCount): ?int
    {
        if ($query->entityType !== 'product') {
            // Analytics tracks storefront product-search behavior only —
            // category/vendor/CMS/blog search is not user-facing "search"
            // in the funnel-analytics sense this table models.
            return null;
        }

        return DB::table('search_queries')->insertGetId([
            'tenant_id' => $query->tenantId,
            'store_id' => $query->storeId,
            'user_id' => null,
            'normalized_query' => mb_strtolower(trim($query->term)),
            'raw_query' => $query->term,
            'result_count' => $resultCount,
            'locale' => $query->locale,
            'created_at' => now(),
        ]);
    }
}
