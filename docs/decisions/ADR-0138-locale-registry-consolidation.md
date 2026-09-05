# ADR-0138: Locale Registry Consolidation and Direction/Fallback Resolution

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0138                              |
| Status      | Accepted                              |
| Date        | 2026-09-05                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-18                              |

## Context

Source audit found the Phase-01 Localization/Markets scaffolding further along than roadmap docs suggested: `languages`/`currencies`/`countries` reference tables, a full `Market`+5-pivot schema, and a `ContextManager`/resolver chain already existed — but with real drift. `App\Core\Localization\Enums\Direction::RTL_LOCALES` (8 hardcoded locales) and an independent, shorter, unnamed 4-entry array inline in `LocaleResolver.php` disagreed with each other and with the richer, already-populated `languages.direction` column. `LocaleManager::getSupportedLocales()` read `config('app.supported_locales')` (hardcoded `['en','ar']`) while a DB-backed, admin-manageable `languages` table sat unused for that purpose. Every locale-bearing column was `varchar(10)` — enough for `zh-Hans-CN` exactly, with zero room for anything longer, which would have reintroduced an architectural ceiling at the exact moment Phase-18 is chartered to remove one.

## Decision

**One authoritative Locale registry, DB-driven end to end.** `languages` remains the single table (not split into a separate "language" + "locale" table) — it already fits any BCP-47-lite tag; a new nullable `language_code` column adds only the bare-language grouping key (`ar` for `ar`/`ar-SY`/`ar-SA`) for Control Center UI grouping and Accept-Language family fallback, self-derived for the existing seed rows.

**`App\Core\Localization\ValueObjects\LocaleCode`** is the one normalization/validation boundary — `language`, `language-REGION`, `language-Script`, `language-Script-REGION` (e.g. `ar`, `ar-SY`, `de-CH`, `zh-Hans-CN`, `sr-Latn-RS`), deterministic casing. Every locale-bearing column across Catalog, CMS, Order/Checkout, Search, and reference-data tables is widened to `varchar(35)` in one migration, so no partial/inconsistent-width graph is ever left behind.

**Direction is 100% DB-driven.** Both hardcoded RTL lists are deleted outright. `Direction` enum keeps only its two cases; `App\Core\Localization\Services\LocaleFallbackResolver::resolveActiveLocale()` is the one fallback-chain lookup (requested → Market default → Store's default Market → registered bare-language match → platform default `Language` → null), and `LocaleManager::direction()` reads *that* resolved Language row's own `direction` column. Only when literally zero `Language` rows exist (absolute bootstrap failure) does resolution fall back to `Direction::LTR` — never a per-locale guess from a PHP list.

`LocaleManager::getSupportedLocales()` now queries `Language::where('is_active', true)`, cached and invalidated on every Control Center write; `config('app.supported_locales')` is demoted to a bootstrap-only seed default consulted solely by `ReferenceDataSeeder` on a fresh install.

## Consequences

- Adding a new locale (any BCP-47-lite shape) via the new `LanguageManager` Control Center screen requires zero application code and zero migration — proven by an end-to-end test.
- Exactly one code path determines a Locale's direction; the two-list drift is structurally impossible to reintroduce (a regression test asserts `RTL_LOCALES` appears nowhere in the codebase).
- Existing `en`/`ar`/`de`/`fr`/`es`/`zh` installations are unaffected — the widening migration is metadata-only in PostgreSQL and preserves every existing row verbatim.

## References

- `docs/phases/PHASE-18-INTERNATIONALIZATION-MARKETS-REGIONALIZATION-RTL.md`
- `app/Core/Localization/ValueObjects/LocaleCode.php`, `Services/LocaleFallbackResolver.php`
