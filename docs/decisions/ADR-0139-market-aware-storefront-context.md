# ADR-0139: Market-Aware Context Resolution on the Storefront Route

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0139                              |
| Status      | Accepted                              |
| Date        | 2026-09-05                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-18                              |

## Context

`App\Core\Context\ContextManager` and its seven resolvers (`TenantResolver`→`StoreResolver`→`ChannelResolver`→`MarketResolver`→`LocaleResolver`→`CurrencyResolver`→`UserResolver`) already existed and ran in the correct dependency order via `ResolveContextMiddleware` — but only on the auth and platform-admin route groups. The public storefront route group (every customer-facing page) instead ran only `SetLocaleAndDirectionMiddleware` (query/Accept-Language/config-default only) followed by `ResolveStorefrontThemeMiddleware`, which independently re-resolved Store/Market/Currency/Channel from the host and wrote them directly into `ContextManager` — a second, competing context-resolution authority that never consulted `MarketResolver`/`LocaleResolver` at all. This meant the single highest-traffic route group in the platform never got Market-aware Locale/Currency resolution, and Theme resolution could silently clobber a context value ResolveContextMiddleware would otherwise have resolved correctly.

## Decision

**Context resolves before Theme, always, with exactly one authority for each.** `ResolveContextMiddleware` is added to the storefront route group, ordered *before* `ResolveStorefrontThemeMiddleware`; `SetLocaleAndDirectionMiddleware` is removed from that group (its basic resolution is now fully superseded). `ResolveStorefrontThemeMiddleware` is rewritten to only ever *consume* `ContextManager::getStore()` — it no longer imports `MarketContext`/`CurrencyContext`/`ChannelContext`/`DomainAddressingService` at all, and cannot re-resolve or overwrite anything `ResolveContextMiddleware` already established. `ThemeResolver` remains authoritative for Theme resolution only; `ContextManager`/its resolvers remain authoritative for request context only.

`MarketResolver` gains a tier-0 step consulting the (also-new, ADR-0140) hostname-based `DomainAddressingService::resolveHostContext()` before any query/header override — an explicit Market-domain mapping outranks even an explicit `?market=` query parameter, per the platform's detection-precedence order (explicit URL/domain mapping is the highest tier).

`ContextManager`'s existing `$this->app->scoped()` binding (request-scoped, automatically flushed) is unchanged and is now covered by an explicit architecture-regression test asserting it never becomes a `singleton()`.

## Consequences

- Every storefront request now resolves a real, Market-aware Locale/Currency/Store/Channel context — closing the single most consequential routing gap found in the Phase-18 source audit.
- Exactly one deterministic resolution order applies platform-wide: Tenant → Store → Channel → Market → Locale → Currency → User, with Vendor resolved separately only inside the Control-Center-only `ControlCenterContextMiddleware`.
- A source-level regression test (`ResolveStorefrontThemeMiddleware.php` contents grep) and a request-level test (an explicit `?market=` selection surviving through Theme resolution) both guard against this defect reappearing.

## References

- `docs/phases/PHASE-18-INTERNATIONALIZATION-MARKETS-REGIONALIZATION-RTL.md`
- `routes/web.php` (storefront group), `app/Core/Context/Middleware/ResolveContextMiddleware.php`, `app/Core/Theme/Http/Middleware/ResolveStorefrontThemeMiddleware.php`
