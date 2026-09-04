# Search Module Specification

**Module Namespace**: `Modules\Search`
**Root Path**: `modules/Search/`
**Status**: Active Production Module (PHASE-17)

---

## 1. Overview

One authoritative search contract — `Modules\Search\Contracts\SearchServiceInterface` — wrapping Laravel Scout (`^11.6`) + Meilisearch (`meilisearch/meilisearch-php` `^1.17`). Application code (storefront search page, future Control Center admin search) never touches Scout's builder or Meilisearch's client directly outside `modules/Search/`.

## 2. Query Isolation — No Cross-Tenant/Store Leakage

`Modules\Search\DTOs\SearchQuery` requires `tenantId`/`storeId` in its constructor with no nullable escape hatch — a storefront caller structurally cannot construct an unscoped query. Belt-and-suspenders: `Modules\Catalog\Models\Product::shouldBeSearchable()` means an unpublished product is **never indexed at all** (not just filtered at query time), and `ScoutSearchService` re-verifies store membership in PHP after the Scout/Meilisearch query returns (defensive, since Scout's driver-agnostic `where()` does not guarantee "array contains" semantics identically across every engine — a real Meilisearch deployment's filterable-array-attribute behavior is stronger than the `collection` test driver's).

## 3. Indexed Documents

Phase-17 indexes `Product` only (the highest-value searchable domain) — `toSearchableArray()` embeds every locale's translated name/description as locale-suffixed fields (`name_en`, `name_ar`, ...) in one document per product, a pragmatic simplification versus one Meilisearch index per locale, avoiding Scout's single-index-per-model constraint while still supporting genuinely multilingual full-text search. Category/Vendor/CMS content indexing is named as future scope, not silently promised.

## 4. Merchandising

`is_featured` (from `ProductStoreListing`) is embedded as a ranking signal in the same filtered document — a promoted/pinned product still passes every tenant/store/publish-status filter; there is no separate unfiltered merchandising query path.

## 5. Search Analytics

Every query (including no-result queries) is recorded to `search_queries` (`tenant_id, store_id, normalized_query, raw_query, result_count, locale`) — no unnecessary PII, `user_id` nullable. No AI search is built; `SearchServiceInterface` reserves the seam for a future natural-language search method, not implemented here.

## 6. Tests

`tests/Feature/Search/{ScoutSearchServiceTest,SearchMerchandisingTest}.php` — proves tenant/store isolation, draft-product exclusion, and that merchandising cannot bypass eligibility.
