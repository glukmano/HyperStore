# ADR-0131: Navigation Registry — Shared Module/Plugin Registration Contract

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0131                              |
| Status      | Accepted                              |
| Date        | 2026-09-04                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-15                              |

## Context

The Control Center shell had no sidebar/navigation menu prior to Phase-15 — every module's admin screens (where they existed at all) were reachable only by a hand-typed URL, with no discovery mechanism and no permission-aware filtering. Master §12 requires one unified professional Control Center shell whose views are governed by `Identity + Role + Permission + Context + Plan + Feature Flag`. The owner's Phase-15 delta additionally requires that the registration contract used for navigation (and theme) extension points be usable by a future Plugin SDK without redesign, while explicitly not building that SDK this phase.

## Decision

- `App\Core\Navigation\Contracts\NavigationRegistryInterface` exposes exactly one registration method, `register(NavigationItem $item): void`, bound as a singleton in `AppServiceProvider`.
- `App\Core\Navigation\DTOs\NavigationItem` is an immutable value object carrying `key`, `label`, `routeName`, `group`, `permission`, `context` (`tenant`|`vendor`|`super-admin`|`all`), `icon`, `order`. Route existence and Spatie permission checks are resolved lazily at render time (`visibleGrouped()`), not at registration time, so registration order across modules never matters.
- Every Core subsystem and first-party Module registers its own sidebar entries from its own `ServiceProvider::boot()` (mirroring the exact pattern already established by `ProductTypeRegistryInterface` module registration) — the shell layout has no hardcoded knowledge of any module's screens.
- This is the single registration path. A future Plugin SDK registering a navigation entry calls `NavigationRegistryInterface::register()` identically to a Module today — no parallel or privileged registration mechanism was introduced for Core/Modules that a Plugin would need to bypass.

## Consequences

- Adding a new admin screen to any module requires exactly one `NavigationItem::register()` call in that module's own `boot()` — the shared shell layout (`resources/views/layouts/control-center.blade.php`) never needs to change.
- Permission-gating a nav item is declarative (`permission: 'resource.action'`) and reuses the codebase's existing Spatie seeding convention — no separate ACL system was introduced.
- The Plugin SDK, when built, extends this contract rather than replacing it.

## References

- PROJECT_MASTER_PLAN.md §12 (Control Center / Super Admin)
- ADR-0006 (Theme and Plugin Isolation)
- `docs/phases/PHASE-15-CONTROL-CENTER-STOREFRONT-THEME-SYSTEM.md`
