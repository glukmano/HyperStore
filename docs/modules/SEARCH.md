# Search Module Specification

**Module Namespace**: `Modules\Search`
**Root Path**: `modules/Search/`
**Status**: Active Production Module (Phase-17, entity coverage completed in the Phase-17 completion delta)

---

## 1. Overview

One authoritative search contract — `Modules\Search\Contracts\SearchServiceInterface` — wrapping Laravel Scout (`^11.6`) + Meilisearch (`meilisearch/meilisearch-php` `^1.17`). Application code (storefront search page, Control Center synonym/merchandising/analytics screens) never touches Scout's builder or Meilisearch's client directly outside `modules/Search/` (enforced by `tests/Feature/Architecture/Phase17ArchitectureTest.php`, whitelisting exactly the five indexed models below).

## 2. Query Isolation — No Cross-Tenant/Store Leakage

`Modules\Search\DTOs\SearchQuery` requires `tenantId`/`storeId` in its constructor with no nullable escape hatch. Belt-and-suspenders: every indexed model's own `shouldBeSearchable()` means an ineligible record is **never indexed at all** (not just filtered at query time), **and** `ScoutSearchService` re-verifies eligibility in one batched Postgres query per `search()` call (never a per-hit N+1 lookup) — checking the SAME authoritative status/visibility fields the rest of the platform uses (e.g. `product_store_listings.status/visibility`, `vendors.operational_status`, `pages.status/published_at`), not just store membership.

## 3. Indexed Entities

Five entities, selected via `SearchQuery::$entityType` (`product` default, `category`, `vendor`, `cms_page`, `blog_post`), each with its own model implementing Scout's `Searchable` trait + `shouldBeSearchable()`/`searchableAs()`/`toSearchableArray()`:

- **Product** (`modules/Catalog/Models/Product.php`) — one document per product, every locale's translated name/description as locale-suffixed fields; store-scoped via `store_ids`.
- **Category** (`modules/Catalog/Models/Category.php`) — store-scoped via `category_stores` pivot; indexed only when `status=active` and assigned to at least one store.
- **Vendor** (`modules/Marketplace/Models/Vendor.php`) — tenant-wide (not per-store; a vendor storefront is not store-scoped the way a product listing is), indexed only when `operational_status` is Active (`canSell()`).
- **CMS Page** (`modules/Cms/Models/Page.php`) — tenant-wide, indexed only when `isPublished()`.
- **Blog Post** (`modules/Cms/Models/BlogPost.php`) — tenant-wide, indexed only when `isPublished()`.

## 4. Merchandising — PostgreSQL-Authoritative

`search_merchandising_rules` (tenant/store/query_term/category_id/product_id/pin_position/is_active) is the source of truth for pinned results — never Meilisearch's own ranking config alone. `ScoutSearchService::pinnedProductIds()` reads it at query time and re-sorts already-eligibility-filtered hits; a pinned-then-archived product is excluded upstream by the same batched revalidation as §2, never a separate unfiltered merchandising path. Managed via Control Center's `Modules\Search\Livewire\MerchandisingManager` (`/control-center/platform/search/merchandising`).

## 5. Synonyms — PostgreSQL-Authoritative

`search_synonyms` (tenant/locale/term/synonyms jsonb) is the source of truth; Meilisearch's own index-settings synonym config is a disposable, rebuildable projection restorable from this table on a full reindex — never the other way around. Managed via `Modules\Search\Livewire\SynonymManager` (`/control-center/platform/search/synonyms`).

## 6. Search Analytics

Every product-entity query is recorded to `search_queries` (`tenant_id, store_id, normalized_query, raw_query, result_count, locale`) — including no-result queries — with `search_queries.id` returned to the caller (`SearchResultSet::$searchQueryId`) so a subsequent result click can be attributed. Clicks live in their own child table, `search_clicks` (`search_query_id, tenant_id, product_id, result_position`), so **one query execution can own multiple clicks** — a single `clicked_product_id` column on `search_queries` could not. `SearchServiceInterface::recordClick()` is the one write path; the storefront wires it from `App\Livewire\Storefront\SearchResultsPage::recordClick()`. Category/Vendor/CMS/Blog search is not recorded to this table — it models storefront product-search funnel behavior specifically. `Modules\Search\Livewire\SearchAnalyticsDashboard` (`/control-center/platform/search/analytics`) surfaces top queries and no-result queries over a rolling 30-day window.

## 7. Operational Requirements

- **Local dev**: `SCOUT_DRIVER=collection` (test/dev default, no daemon required) or `SCOUT_DRIVER=meilisearch` with `MEILISEARCH_HOST`/`MEILISEARCH_KEY` pointing at a running Meilisearch instance.
- **Production**: a supervised Meilisearch process (or managed Meilisearch Cloud instance), `SCOUT_QUEUE=true` (already set) so indexing is queue-driven, never synchronous on save, and `php artisan scout:sync-index-settings` run once per deployment (and after any `config/scout.php` `index-settings` change) to push filterable-attribute/synonym configuration into Meilisearch — this is what makes Postgres-authoritative synonyms/merchandising actually take effect in the live index.

## 8. Tests

`tests/Feature/Search/{ScoutSearchServiceTest,SearchMerchandisingTest,SearchEntityCoverageTest}.php` — proves tenant/store isolation and draft/unpublished exclusion for all five entities, suspended-vendor exclusion, and that a pinned-then-ineligible product never surfaces. A real (non-fake-driver) Meilisearch integration test was evaluated during the Phase-17 completion delta but intentionally not committed: the only Meilisearch daemon reachable in this environment was a pre-existing shared instance already holding unrelated documents in a `products` index (foreign field names, no relation to this schema) — writing test data into it risked corrupting real, unrelated state outside this repository's control. The safe way to exercise real-Meilisearch-specific behavior (typo tolerance, facet distribution) is a dedicated, disposable Meilisearch container in CI, matching the `PostgreSql*Test` skip-if-unavailable pattern; that dedicated-daemon wiring is not yet set up.
