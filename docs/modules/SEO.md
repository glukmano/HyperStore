# SEO Module Specification

**Module Namespace**: `Modules\Seo`
**Root Path**: `modules/Seo/`
**Status**: Active Production Module (PHASE-17)

---

## 1. Overview

One central `Modules\Seo\Services\SeoMetadataService` — never page-specific duplicated `<meta>` logic. Covers canonical/title/description resolution, structured data (JSON-LD via `Modules\Seo\Contracts\StructuredDataBuilderInterface` implementations: `ProductSchemaBuilder`, `ArticleSchemaBuilder`, `BreadcrumbSchemaBuilder`), sitemap generation, and robots.txt.

## 2. Visibility Enforcement — Never a Parallel Concept

Every resolution path re-checks the exact same authoritative status fields the rest of the platform already uses: `ProductStoreListing.status/visibility` for products, `Page.status`/`isPublished()` for CMS pages, `BlogPost.status`/`isPublished()` for blog posts. `SubjectNotVisibleException` is thrown rather than ever returning metadata for unpublished/inactive content.

## 3. Sitemap

`Modules\Seo\Services\SitemapGenerator` (queued via `GenerateSitemapJob`, never synchronous in a customer-facing request) includes only published+visible product listings, published pages, published blog posts, and `Active`-operational-status vendors — the same status checks as §2, enforced at generation time, never filtered only after the fact.

## 4. Hreflang — Real-but-Partial Seam

`SeoMetadataService::resolveAlternateLocaleUrls()` produces alternate-locale URLs using only `config('app.supported_locales')` (currently `en`/`ar`) — no fabricated Phase-18 Market/domain semantics. Full Market-domain/Vendor-domain hreflang expansion is a documented Phase-18 extension; the seam's contract shape already accommodates it without rework.

## 5. Tests

`tests/Feature/Seo/{SeoMetadataTest,SitemapGeneratorTest}.php` — proves no draft/inactive/suspended leakage and correct hreflang seam output for the currently-supported locales.
