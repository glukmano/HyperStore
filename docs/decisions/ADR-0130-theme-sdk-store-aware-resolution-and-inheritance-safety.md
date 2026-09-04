# ADR-0130: Theme SDK — Store-Aware Resolution, Inheritance-Chain Safety, Plugin-Ready Registration

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0130                              |
| Status      | Accepted                              |
| Date        | 2026-09-04                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-15 (Theme SDK first implementation, completing ADR-0006's declared boundary) |

## Context

ADR-0006 declared the Theme/Plugin isolation boundary (themes live in `themes/<name>/`, manifest-driven, `themes/default` is the fallback) but created no code — "Phase 01 creates no Theme or Plugin code." Phase-15 is the first phase to implement the Theme SDK (`app/Core/Theme/`). During planning, the owner issued an explicit delta requiring: (1) active theme resolution must be Store-aware, never a hardcoded `'default'` literal in Storefront Core; (2) the Theme and Navigation registration contracts must be usable by a future Plugin SDK without redesign, though the Plugin SDK itself is not built this phase; (3) child-theme resolution must support a safe multi-level inheritance chain (not a single level) with cycle detection, missing-parent handling, a bounded maximum depth, and deterministic fallback to `default`.

## Decision

- `App\Core\Theme\Contracts\ThemeResolverInterface::resolveForStore(?Store $store): ResolvedTheme` reads a Store's `active_theme` column (new additive migration) and falls back to `'default'` only when unset or the Store is null — never a hardcoded literal elsewhere in Storefront Core.
- `App\Core\Theme\ThemeResolver::resolveChain()` walks a theme's `extends` chain with a visited-set for cycle detection, treats an unregistered parent as a stop condition (missing-parent handling), enforces `MAX_INHERITANCE_DEPTH = 5`, and always appends `'default'` to the resolved chain if not already present (deterministic terminal fallback) — every failure mode degrades to `'default'`, never a 500.
- `App\Core\Theme\Contracts\ThemeRegistryInterface::register(ThemeManifest $manifest): void` is the single registration entry point. Today only the built-in `themes/default` theme registers, from `AppServiceProvider::boot()`. This is the exact same contract a future Plugin SDK (or a theme-install flow) will call — no Phase-15-only registration path exists.
- Resolved view paths are registered per-request via `Illuminate\Support\Facades\View::replaceNamespace('theme', $resolvedTheme->viewPaths)` in `ResolveStorefrontThemeMiddleware`, giving Blade's `theme::pages.*`/`theme::sections.*`/`theme::layouts.*`/`theme::components.*` a child→parent→default fallback search order matching Laravel's own first-match view-hint resolution. A boot-time default registration (`View::addNamespace('theme', base_path('themes/default'))`) keeps `theme::` resolvable outside storefront requests (console, static analysis).
- Phase-15 ships exactly one theme (`default`, `extends: null`). The inheritance-chain logic is proven against fixture manifests in unit tests, not by shipping a second real theme.

## Consequences

- Adding a second theme later requires only registering its manifest through `ThemeRegistryInterface` and setting `stores.active_theme` — no Storefront Core code change.
- A future Plugin SDK registering a theme or navigation extension reuses `ThemeRegistryInterface::register()` / `NavigationRegistryInterface::register()` verbatim; no redesign is anticipated.
- A malformed or cyclic theme configuration can never break a storefront request — it silently resolves to `default`.

## References

- PROJECT_MASTER_PLAN.md §22 (Theme System)
- ADR-0006 (Theme and Plugin Isolation)
- `docs/phases/PHASE-15-CONTROL-CENTER-STOREFRONT-THEME-SYSTEM.md`
