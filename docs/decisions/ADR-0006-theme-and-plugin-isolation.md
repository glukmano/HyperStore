# ADR-0006: Theme and Plugin Isolation

| Field        | Value                                |
|-------------|--------------------------------------|
| ID          | ADR-0006                             |
| Status      | Accepted                             |
| Date        | 2026-08-31                           |
| Deciders    | Project Lead, Platform Architect     |
| Phase       | PHASE-01 (ADR only — SDK implementation deferred to Phase 03+) |

## Context

HyperStore requires a storefront theming system and a third-party plugin extension system.
Both must allow customization without modifying Core platform code.

We needed to decide the boundaries and isolation rules before any Theme or Plugin code is written.

## Decision

### Themes

- Themes live in `themes/<theme-name>/` with a `theme.json` manifest.
- A Theme may only override: Blade views, CSS/JS assets, Livewire component views.
- A Theme must NOT contain business logic, models, or database migrations.
- The default theme lives at `themes/default/` and is the fallback for all stores.
- Child themes may extend the default theme by declaring `"extends": "default"` in `theme.json`.
- The **Theme SDK** (`app/Core/Theme/`) manages theme resolution and view overrides.

### Plugins

- Plugins live in `plugins/<plugin-name>/` with a `plugin.json` manifest.
- A Plugin may register: Artisan commands, Event listeners, API endpoints, Livewire components, admin UI blocks.
- A Plugin must NOT modify Core classes, override Module internals, or change database migrations directly.
- Plugins may declare new database migrations in `plugins/<name>/database/migrations/`.
- Plugins are loaded after all Modules (the Module Kernel boots Modules first, then Plugins).
- The **Plugin SDK** (`app/Core/Plugin/`) enforces the permission boundary via a declared manifest permission system.

### Isolation rules (enforced by architecture tests):

| Layer | Can read | Can write | Can override |
|---|---|---|---|
| Theme | Core views, assets | Theme-owned views only | Blade views only |
| Plugin | Core contracts, Module events | Plugin-owned DB, routes | Admin UI extension points |
| Module | Core contracts | Module-owned DB | Nothing outside own namespace |
| Core | Everything | Core only | N/A |

## Consequences

- Phase 01 creates no Theme or Plugin code — this ADR records the boundaries.
- Architecture tests will assert that `themes/` contains no PHP business logic.
- Architecture tests will assert that `plugins/` does not reference Module internals.
- The Theme SDK and Plugin SDK will be implemented in dedicated phases.

## References

- PROJECT_MASTER_PLAN.md §Plugin Architecture, §Theme Architecture
- ADR-0001 (Modular Monolith — module isolation rules)
- ADR-0003 (Module Kernel — load order)
