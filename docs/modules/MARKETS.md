# Markets Specification

**Namespace**: `App\Core\Markets`, `App\Core\ReferenceData` (Country/Currency reference data), `App\Core\Routing` (hostname resolution)
**Root Path**: `app/Core/Markets/`, `app/Core/ReferenceData/`, `app/Core/Routing/`
**Status**: Active Platform Core (Phase-01 foundation, completed Phase-18)

---

## 1. Overview

A Market represents a commercial regional context — countries, locales, currencies, timezone, domain — never a synonym for Country or Language. `market_countries`/`market_currencies`/`market_languages` are independent many-to-many pivots, so a Market spans multiple countries (a Middle-East Market) or multiple locales on one currency (Switzerland: `de-CH`/`fr-CH`/`it-CH`, `CHF`) without ever forcing one-Market-one-Country or one-Country-one-Language. Lives under `App\Core\Markets`/`App\Core\ReferenceData` (built as Phase-01 Core scaffolding), not a `modules/Markets/` directory — see `docs/modules/LOCALIZATION.md` §1 for the same naming note.

## 2. Reference Data

`countries` (`App\Core\ReferenceData\Models\Country`) — ISO 3166-1 alpha-2/alpha-3 codes are the authoritative key, never a translated display name. `currencies` (`App\Core\ReferenceData\Models\Currency`) — ISO 4217 codes; `MoneyValue`/`brick/money` remain the sole money-math authority (Pricing-owned) — this reference table is descriptive/admin-facing only, never a second currency engine. Both are managed from Control Center (`CountryManager`/`CurrencyManager`, `countries.*`/`currencies.*` permissions) — activate/deactivate only, no destructive delete, and the platform default of each is protected from deactivation.

## 3. Market Model and Defaults Integrity

`App\Core\Markets\Models\Market` + `MarketCountry`/`MarketCurrency`/`MarketLanguage`/`MarketChannel`/`StoreMarket`. `Market.default_locale_code`/`default_currency_code` are the **one** authoritative fields every resolver (`MarketResolver`/`LocaleResolver`/`CurrencyResolver`) reads — `market_languages.is_default`/`market_currencies.is_default` are kept transactionally synchronized to them by `App\Core\Markets\Services\MarketDefaultsService`, never an independently-drifting second source of truth. A new Market's default Locale/Currency is bootstrapped as a real membership row at creation time (`MarketDefaultsService::bootstrapDefaults()`); changing a Market's default (`setDefaultLocale()`/`setDefaultCurrency()`) verifies the target is already an active member before flipping it, inside one DB transaction. PostgreSQL partial unique indexes back this at the DB level: at most one global default `Language`/`Currency`, at most one default Locale/Currency per Market, at most one default Market per Store.

Managed from Control Center (`App\Livewire\ControlCenter\MarketManager`, `markets.view`/`markets.manage`) — deactivate-only, never hard-deleted (a Market may be referenced by historical Order/Checkout snapshots and must never be deleted out from under them).

## 4. Store/Channel/Market Relationships and Product Eligibility

Already fully modeled (`store_markets`, `market_channels`, `store_channels`) — Phase-18 added no new join tables. The one authoritative answer to "is this Product sellable in this customer context" remains `Modules\Catalog\Models\ProductStoreListing`; Market/Store membership is one additional filter dimension checked *before* it (an inactive Market or an inactive `store_markets` row short-circuits to "not available" independent of any per-product listing state) — not a replacement eligibility system. No `product_countries` table exists or is needed: `Product → ProductStoreListing(store) → StoreMarket(store,market) → MarketCountry(market,country)` already expresses country-level availability via existing tables.

## 5. Domain / Hostname Resolution

Three coexisting, deliberately-not-merged hostname stacks, all sharing one collision-safe registry — see ADR-0140 for the full rationale:

- **Store domains** — `App\Core\Stores\Models\StoreDomain` (`store_domains`), resolved by `App\Core\Routing\DomainAddressingService`.
- **Regional (Market) domains** — `App\Core\Markets\Models\MarketDomain` (`market_domains`), referencing `store_markets.id` directly so one hostname always resolves an unambiguous Store+Market pair (never a Market alone, since one Market may span several Stores). Managed from Control Center (`App\Livewire\ControlCenter\DomainManager`, `domains.*`).
- **Vendor domains** — `Modules\Marketplace`'s `vendor_domains` + `VendorStorefrontResolver`, its own bounded context (self-service verification), unchanged.

`App\Core\Routing\HostnameClaimService` + the `hostname_claims(normalized_hostname UNIQUE, owner_type, owner_id)` table is the one global arbiter across all three — each model claims its hostname inside its `creating` event, backed by a real DB unique constraint, so the same host can never be claimed by two of Store/Market/Vendor even though each table also keeps its own per-table `UNIQUE(domain)`. `App\Core\Routing\HostnameNormalizer::normalize()` (lowercase, no scheme/path/port/trailing dot) is used by every write and read path. New domains of every kind default to `is_verified = false`.

## 6. Localized URL Strategy

See ADR-0141. Every storefront route exists both bare and under a `{locale}` path prefix (`localized.storefront.*` route names); `App\Core\Localization\Services\LocalizedUrlResolver` picks the canonical form per resolved Market (single-Locale Market/host → bare URL is canonical; multi-Locale-per-host → the prefixed URL is canonical). `Modules\Seo\Services\SeoMetadataService::resolveAlternateLocaleUrlsForMarket()` emits hreflang only for active Market-member Locales that genuinely have content, with `x-default` pointing at the Market's own default Locale.

## 7. Timezone

Every timestamp column remains UTC — unchanged. `Modules\Order\Services\BusinessTimezoneResolver` (Market.timezone → Store.settings['timezone'] → exception) is the one IANA-identifier-validated resolver, reused (not reimplemented) for both order-numbering and the new `orders.timezone_snapshot` column, frozen at Order-creation time (`Order::displayTimezone()`). A Market's timezone may change later (Markets are deactivate-only, never deleted) without silently reinterpreting an already-placed Order's historical timestamps.

## 8. Search / Currency / Tax / Shipping Integration

Market supplies **context** to already-owned domains, never a second calculation engine: `PricingContext.marketId`/`.currency` (Pricing-owned `PriceResolver` unchanged), `TaxContext.countryCode` (Pricing/Tax-owned `TaxCalculator` unchanged), `ShippingContext.marketId`/`.currency` (Shipping-owned `ShippingZoneMatcher` unchanged), `SearchQuery.locale` driving `Modules\Search\Services\ScoutSearchService`'s locale-scoped `attributesToSearchOn` (a search in one Locale ranks only on that Locale's own fields plus the configured fallback — never every registered Locale's fields equally).

## 9. Tests

`tests/Feature/Markets/MarketDefaultsIntegrityTest.php`, `tests/Feature/Routing/HostnameClaimPostgreSqlTest.php`, `tests/Feature/Context/ContextBeforeThemeOrderingTest.php`, `tests/Feature/Seo/HreflangMarketTest.php`, `tests/Feature/Search/SearchLocaleScopingTest.php`, `tests/Feature/Order/OrderCreationTest.php` (timezone snapshot cases), `tests/Feature/ControlCenter/Phase18LocalizationScreensTest.php`, `tests/Feature/Architecture/Phase18ArchitectureTest.php`.
