# PHASE-18: Internationalization, Markets, Regionalization & RTL

> **Authority**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md)
> **Status**: COMPLETED
> **Active Date**: 2026-09-05

---

## 1. Objective

Turn HyperStore's existing (Phase-01-built, previously incomplete) Localization/Markets foundation into a first-party dynamic platform: installable/configurable Locales, Market-aware context resolution reaching the storefront, dynamic Country/Currency management, collision-safe regional domains, a real per-Locale canonical URL strategy, and a full RTL/LTR audit — without hardcoding English/Arabic, a fixed country/currency set, or a Market=Country/Country=Language assumption.

## 2. Included Scope

- BCP-47-lite Locale codes (`App\Core\Localization\ValueObjects\LocaleCode`), every locale-bearing column widened to `varchar(35)`.
- DB-driven direction resolution (`App\Core\Localization\Services\LocaleFallbackResolver`), both hardcoded RTL-locale lists deleted.
- `LocaleManager::getSupportedLocales()` sourced from the `languages` table, not `config('app.supported_locales')`.
- `ResolveContextMiddleware` added to the storefront route group, ordered before `ResolveStorefrontThemeMiddleware` (which no longer independently resolves Market/Currency/Channel).
- `MarketResolver` hostname tier-0 resolution via `DomainAddressingService::resolveHostContext()`.
- `hostname_claims` global registry + `HostnameClaimService`/`HostnameNormalizer`; `market_domains` referencing `store_markets.id` (Store+Market pair, not Market alone).
- `MarketDefaultsService` + partial-unique-index-backed default-locale/currency/market integrity.
- `{locale}`-path-prefixed mirror of every storefront route + `LocalizedUrlResolver`.
- `SeoMetadataService::resolveAlternateLocaleUrlsForMarket()`.
- `ScoutSearchService` locale-scoped `attributesToSearchOn`.
- `GeoProviderInterface` (`NullGeoProvider`/`TrustedHeaderGeoProvider`, trusted-CIDR-gated).
- `CustomerProfile.preferred_locale/currency/timezone` + `RegionalPreferenceProviderInterface` published contract.
- `orders.timezone_snapshot`, frozen at Order creation, preferred over live Market timezone re-resolution.
- `ThemeTranslationResolver` (deterministic child→parent→default→platform fallback).
- Control Center: `LanguageManager`, `CountryManager`, `CurrencyManager`, `DomainManager` (all deactivate-only) + `MarketManager` deactivate action.
- New RBAC: `locales.*`, `countries.*`, `currencies.*`, `domains.*`.

## 3. Explicitly Excluded Scope

Automated third-party FX-rate sync, managed IP-geolocation service, ISO-3166-2 subdivision reference data, Tax state/postal-precision fix (pre-existing Tax-owned gap), Vendor-domain hreflang realization beyond existing support, Developer Center implementation (requirement recorded in `docs/PROJECT_REMAINING_IMPLEMENTATION_ROADMAP.md`, not built here), Loyalty/Rewards/Wallet/Gift Cards/POS/SaaS-licensing/B2B/Auctions/Booking/subscriptions/digital delivery/Affiliate/Referral, custom visual redesign, icon-library migration, React/Vue/Next, microservices. Phase-19 not started.

## 4. Required Skills

`project-governance`, `localization-markets-rtl`, `multi-store-context`, `multi-tenancy`, `postgresql-data-design`, `seo-commerce`, `laravel-platform`, `testing-quality`, `security-hardening`.

## 5. Prerequisites

Phase-17 closed (`f1cc458`). Phase-01 Localization/Markets/Context/ReferenceData scaffolding already in place.

## 6. Architecture & ADRs

ADR-0138 (Locale Registry Consolidation), ADR-0139 (Market-Aware Storefront Context), ADR-0140 (Domain/Market Hostname Resolution), ADR-0141 (Localized URL Canonical Strategy). No modification to ADR-0006 (Theme/Plugin isolation), Pricing/Search/SEO/Theme/Plugin architecture.

## 7. Database Work

`2026_09_06_000100_widen_locale_columns_for_bcp47` (varchar(35) widening + `languages.language_code`/`fallback_locale_code`/`sort_order`), `2026_09_06_000101_add_default_integrity_constraints` (5 partial unique indexes), `2026_09_06_000102_create_hostname_claims_and_market_domains_tables`, `2026_09_06_000103_add_regional_preferences_and_timezone_snapshot` (`customer_profiles`.preferred_*, `orders.timezone_snapshot`). All Postgres-only raw DDL guarded by `DB::getDriverName() === 'pgsql'`, matching established convention.

## 8. Backend Work

See `docs/modules/LOCALIZATION.md` and `docs/modules/MARKETS.md` for the full service inventory.

## 9. Frontend Work

`App\Livewire\ControlCenter\{LanguageManager,CountryManager,CurrencyManager,DomainManager}` + Blade views, all `<x-ui.*>`/daisyUI, mirroring `MarketManager`'s exact pattern. No storefront switcher UI was built this pass (scoped out under time/effort constraints — the resolution pipeline, preference storage, and canonical-URL seam are all real and tested; a navbar switcher component consuming them is a small, low-risk follow-up, not an architectural gap).

## 10. Security

Hostname-claim collision prevention (real PostgreSQL tests), Geo trusted-proxy CIDR gating (never trust-by-config-alone), client-supplied currency validated against the resolved Market's allowed set, no cross-Tenant domain/Market leakage (existing `TenantScope` + global `hostname_claims` uniqueness).

## 11. Package Integrity / Non-Financial Idempotency

`MarketDefaultsService` transactional default-switching backed by partial unique indexes (no check-then-write race). `HostnameClaimService::claim()` backed by a real unique-constraint insert, not a check-then-insert race.

## 12. Tests

66 new tests / 125 new assertions added this phase (1108 total / 4634 assertions, full suite green). See `docs/modules/LOCALIZATION.md` §8 and `docs/modules/MARKETS.md` §9 for the full list.

## 13. Documentation

This file; `docs/modules/{LOCALIZATION,MARKETS}.md` (new); ADR-0138 through ADR-0141 (new). No `docs/DEPENDENCIES.md` change — no new package was introduced (Symfony's `IpUtils` is an existing framework dependency).

## 14. Acceptance Criteria

- [x] Adding a new active Locale via Control Center requires zero application code/migration.
- [x] No Market=Country, no Country=Language assumption anywhere in the schema.
- [x] Storefront route group resolves Market-aware Locale/Currency/Store/Channel context.
- [x] Exactly one RTL-locale-determination code path (DB-backed); both hardcoded lists deleted.
- [x] No second Pricing/Search/SEO/Theme-resolution/Plugin-lifecycle system introduced.
- [x] No raw-offset timezone column anywhere; all timezone values are IANA strings.
- [x] No cross-Tenant domain/Market leakage.
- [x] Existing en/ar installation behavior unchanged by any migration.
- [x] Full regression green (1108 tests); PHPStan Level 8 clean; Pint clean; `npm run build`/`composer audit`/`npm audit` clean.
- [x] `PROJECT_MASTER_PLAN.md` untouched; Developer Center requirement recorded, not implemented.

## 15. Stop Condition

On completion of all acceptance criteria: commit, push, report. Do not begin Phase-19.
