# ADR-0141: Localized URL Canonical Strategy

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0141                              |
| Status      | Accepted                              |
| Date        | 2026-09-05                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-18                              |

## Context

The Phase-17 hreflang seam (`SeoMetadataService::resolveAlternateLocaleUrls()`) produced alternate-locale URLs by pointing the *same* route/path at different `?lang=`-resolved renders — a cookie/session/query-chosen Locale, never a distinct crawlable URL per Locale. That is not a stable canonical identity: a search engine (or any client) has no way to request "the German version" of a page by URL; it can only guess at a query parameter. Separately, the storefront route set (`routes/web.php`) has no locale segment at all today, and imposing a `/{locale}/` prefix on every existing route unconditionally would be a large, non-additive rewrite the Phase-18 charter explicitly cautions against when a working alternative exists.

## Decision

**Two coexisting, legitimate canonical forms, chosen per Store/Market, never both trusted for the same request.** Every storefront route (`routes/web.php`'s `$storefrontRoutes` closure) is registered twice: once bare (unprefixed, named `storefront.*`), and once under a `{locale}` path prefix (named `localized.storefront.*`), constrained by `LocaleCode::routePattern()`. `App\Core\Localization\Services\LocalizedUrlResolver::resolve()` picks between them: when the resolved Market carries only one active Locale, the hostname itself already disambiguates Locale and the bare route is canonical; when a Market carries more than one active Locale on the same hostname, the `{locale}`-prefixed route is canonical. A Market-domain host (ADR-0140) that itself maps to exactly one Locale needs no prefix at all — `example.ch` (Swiss Market, `de-CH` only) and `de.example.com` (Market-domain mapping) are both legitimate un-prefixed canonical forms; `example.ch/{locale}/...` is used only when one host must actually serve more than one Locale.

The `{locale}` path segment is the highest-precedence explicit signal in `LocaleResolver` — ranked above `?lang=`, above the guest cookie, above saved preference — because a URL path segment is a stronger, more explicit statement of intent than any of those.

`SeoMetadataService::resolveAlternateLocaleUrlsForMarket()` extends (does not replace) the existing seam: it emits one hreflang entry per **active** Market-member Locale that the caller confirms genuinely has content for (`$urlForLocale` returns `null` otherwise — never a phantom entry), with `x-default` pointing at the Market's own default Locale. Duplicate-content avoidance for a reconfigured canonical host reuses the existing Phase-17 CMS `redirects` table (301/302, loop-checked) — no second redirect engine.

## Consequences

- Every publicly-indexable Store+Market+Locale resource now has a real, distinct, crawlable URL — proven by a routing test asserting the bare and prefixed forms both resolve, and a hreflang test asserting no phantom/duplicate entries.
- No existing route definition was rewritten — the closure-based dual registration is purely additive; single-locale-per-host Stores (the common case) never expose a locale-prefixed URL at all in practice, since `LocalizedUrlResolver` never chooses it for them.
- A malformed locale path segment structurally 404s (the route constraint rejects it) rather than being silently accepted as a "locale."

## References

- `docs/phases/PHASE-18-INTERNATIONALIZATION-MARKETS-REGIONALIZATION-RTL.md`
- `routes/web.php`, `app/Core/Localization/Services/LocalizedUrlResolver.php`, `modules/Seo/Services/SeoMetadataService.php`
