# ADR-0133: Plugin SDK Kernel & Identity

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0133                              |
| Status      | Accepted                              |
| Date        | 2026-09-04                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-16                              |

## Context

ADR-0006 declared the Plugin boundary (`plugins/<name>/plugin.json`, Plugin SDK at `app/Core/Plugin/`, "Plugins are loaded after all Modules") but deferred all implementation. ADR-0003 states "Future Plugin SDK and Theme SDK will integrate with this kernel, not bypass it," referring to the Module Kernel. The owner's Phase-16 authorization explicitly requires the Module and Plugin systems not be conflated, since they carry different trust/failure semantics (a broken Module today is a deploy-time developer error; a broken Plugin must be a runtime-recoverable, isolated failure).

## Decision

- `app/Core/Plugin/PluginKernel` mirrors `App\Core\Modular\ModuleKernel`'s discover→register→boot shape but is a **structurally independent** class — it does not extend or share instances with `ModuleKernel`/`ModuleRegistry`. `App\Core\Plugin\PluginRegistry` re-implements the same proven Kahn's-topological-sort-with-cycle-detection algorithm independently (duplicated, not shared), since coupling Plugin dependency resolution to the Module Kernel's code would risk destabilizing already-accepted, load-bearing Module behavior for the sake of avoiding ~20 lines of duplication.
- `App\Core\Plugin\PluginServiceProvider` extends Laravel's base `Illuminate\Support\ServiceProvider` directly (not `ModuleServiceProvider`), inheriting `loadRoutesFrom()`/`loadViewsFrom()`/`loadMigrationsFrom()`/`loadTranslationsFrom()`.
- `AppServiceProvider::boot()` sequence: `$moduleKernel->discover()/registerModules()/bootModules();` then `$pluginKernel->discover()/registerPlugins()/bootPlugins();` — Plugins strictly load after all Modules, honoring ADR-0006.
- **Per-request rebuild invariant**: since there is no Octane in this deployment, `AppServiceProvider::boot()` reruns every HTTP request, so `PluginKernel::bootPlugins()` reruns too. A disabled plugin's `register()`/`boot()` simply never executes on the next request — its contributions to `NavigationRegistry`/`ThemeRegistry`/`ProductTypeRegistry`/`PaymentGatewayRegistry`/`CarrierRegistry`/`ShippingMethodTypeRegistry` evaporate for free, with zero ownership-tracking code added to any of those six registries. This is a load-bearing invariant, enforced by an explicit architecture test, not merely assumed.
- **Error isolation**: each plugin's individual `register()`/`boot()` call is wrapped in try/catch inside `PluginKernel`. A thrown exception is logged via the existing `AuditManagerInterface`, the plugin's `consecutive_boot_failures` counter increments, and the loop continues to the next plugin — one broken plugin never crashes the platform. After 3 consecutive boot failures, the plugin auto-transitions to `disabled` to stop an endless per-request crash-retry loop.
- **Platform-level identity only** (Owner Delta, 2026-09-04): a plugin's install/enable/disable state is a single platform-wide row, never tenant/store-scoped — the shared, per-request-rebuilt registries this kernel populates cannot structurally support contextual filtering, so no such feature is attempted.

## Consequences

- Module and Plugin lifecycle can evolve independently without cross-contaminating trust or failure-recovery semantics.
- A future Octane adoption (or any `Cache::remember()`-wrapped registry construction) would silently break the "disabled plugin contributes nothing" guarantee — the architecture test named above exists specifically to catch that regression.
- Tenant/store-scoped plugin entitlements are an explicit, intentional gap left for a future Licensing/SaaS/Feature-Flags phase.

## References

- PROJECT_MASTER_PLAN.md §21 (Plugin System), §3.3 (Extensible by design)
- ADR-0003 (Module Kernel), ADR-0006 (Theme and Plugin Isolation)
- `docs/phases/PHASE-16-PLUGIN-SDK-EXTENSIBILITY-PLATFORM.md`
