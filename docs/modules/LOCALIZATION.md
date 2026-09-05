# Localization Specification

**Namespace**: `App\Core\Localization`, `App\Core\Context` (locale/direction concerns), `App\Core\ReferenceData\Models\Language`
**Root Path**: `app/Core/Localization/`, `app/Core/Context/`
**Status**: Active Platform Core (Phase-01 foundation, completed Phase-18)

---

## 1. Overview

Owns runtime locale/direction resolution and the platform's dynamic Locale registry. This lives under `App\Core\*` rather than a `modules/Localization/` directory — the aspirational module tree in `docs/modules/README.md` names it as a future module, but the actual implementation is Core-layer scaffolding built in Phase-01 and completed here; this doc corrects that naming gap rather than relocating working code.

## 2. The Locale Registry — One Table, Not Two

`languages` (model `App\Core\ReferenceData\Models\Language`) is the platform's Locale registry — `code`, `language_code` (bare spoken-language grouping key, e.g. `ar` for `ar`/`ar-SY`/`ar-SA`), `name`, `native_name`, `direction`, `fallback_locale_code`, `is_default`, `is_active`, `sort_order`. A single table was chosen over a separate languages+locales split: `code` already comfortably holds any BCP-47-lite tag (`ar`, `ar-SY`, `de-CH`, `zh-Hans-CN`, `sr-Latn-RS` — all ≤ 10 chars, and the column is widened to `varchar(35)` for headroom), so a second metadata table would only add join overhead for no benefit. `App\Core\Localization\ValueObjects\LocaleCode::isValid()`/`normalize()` is the one validation/normalization boundary — language, language-REGION, language-Script, language-Script-REGION shapes, deterministic casing.

Managed dynamically from Control Center (`App\Livewire\ControlCenter\LanguageManager`, `locales.view`/`locales.manage`) — activate/deactivate only, never a destructive delete (a Locale may be referenced by historical Order/Checkout snapshots, Market defaults, and content translations).

## 3. Direction — 100% DB-Driven

`App\Core\Localization\Enums\Direction` is exactly two cases (`LTR`, `RTL`) — it holds **no** hardcoded RTL-locale list (the two that previously existed here and in `LocaleResolver` were deleted). `App\Core\Localization\Services\LocaleFallbackResolver::resolveActiveLocale()` is the one fallback-chain lookup (requested locale → Market default → Store's default Market → registered bare-language match → platform default `Language`), and `LocaleManager::direction()`/`LocaleResolver` both read *that* resolved Language row's own `direction` column. Only when zero `Language` rows exist at all (absolute bootstrap failure) does resolution fall back to `Direction::LTR`.

## 4. `LocaleManager` — Supported Locales

`App\Core\Localization\LocaleManager::getSupportedLocales()` queries `Language::where('is_active', true)`, cached under `LocaleManager::ACTIVE_LOCALES_CACHE_KEY` and invalidated on every `LanguageManager` write. `config('app.supported_locales')` is a bootstrap-only seed default consulted solely by `ReferenceDataSeeder` on a fresh install — never read at runtime by anything else (enforced by an architecture test).

## 5. Context Resolution Pipeline

`App\Core\Context\ContextManager` (bound `$this->app->scoped()`, genuinely request-scoped, never leaks between requests) holds Tenant/Store/Channel/Market/Locale/Currency/User/Vendor, populated in that dependency order by `ResolveContextMiddleware`'s seven resolvers. This middleware now runs on the storefront route group too, strictly *before* `ResolveStorefrontThemeMiddleware` (which only ever consumes the already-resolved Store — see ADR-0139). Detection precedence inside `LocaleResolver`/`CurrencyResolver`: explicit `{locale}` path segment → explicit query param/header → guest cookie (`hs_locale`/`hs_currency`) → saved authenticated preference (`Modules\Customers\Models\CustomerProfile.preferred_locale`/`preferred_currency`, read via the published `App\Core\Context\Contracts\RegionalPreferenceProviderInterface` contract — Core never queries a Module's Eloquent model directly) → Market default → platform default. A client-supplied currency is only ever honored when it's an active member of the resolved Market's `market_currencies` set.

## 6. Geo Detection — Inference Only

`App\Core\Context\Contracts\GeoProviderInterface` — `NullGeoProvider` (default, no detection) or `TrustedHeaderGeoProvider` (honors one configured header, e.g. `CF-IPCountry`, only when the request's IP matches a configured trusted CIDR via Symfony's `IpUtils`, `config('platform.trusted_geo_proxies')`). No trusted proxy configured → the header is never consulted, identical to `NullGeoProvider`. Geo output feeds only the lowest-precedence detection tier and is never authorization/tax/legal truth.

## 7. Theme/Plugin Translations

`App\Core\Theme\Services\ThemeTranslationResolver` — a deterministic (not assumed) fallback chain: child Theme → each parent in `ThemeResolver`'s already-computed chain → platform `lang/{locale}/{file}.php` → null (caller falls back to the raw key). Plugin translations reuse Laravel's own `loadTranslationsFrom()` (already available since plugins extend the base `ServiceProvider`) — no plugin-specific mechanism.

## 8. Tests

`tests/Feature/Localization/{LocaleCodeTest,LocaleFallbackResolverTest,LocalizedRoutingTest}.php`, `tests/Feature/LocalizationDirectionTest.php`, `tests/Feature/Context/{ContextBeforeThemeOrderingTest,GeoTrustBoundaryTest}.php`, `tests/Feature/Theme/ThemeTranslationResolverTest.php`, `tests/Feature/Architecture/Phase18ArchitectureTest.php`.
