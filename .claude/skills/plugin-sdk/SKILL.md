---
name: plugin-sdk
description: Enforces the implemented Phase-16 Plugin SDK — manifest schema, platform-level lifecycle, registry reuse, ZIP/signature security, and Core isolation. Use when designing plugin features or reviewing plugin code.
---

# Plugin SDK & Extension Architecture

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 3.3, 21, 26)
- **Implementation**: `app/Core/Plugin/`, `docs/phases/PHASE-16-PLUGIN-SDK-EXTENSIBILITY-PLATFORM.md`, ADR-0133 through ADR-0136, `docs/plugins/*`.

This skill reflects the **actually-built** Phase-16 SDK, not aspirational
design. Where this file and the code in `app/Core/Plugin/` disagree, the code
and `docs/plugins/` are authoritative — update this file rather than trusting
it blindly.

## Core Rules & Mandates

1. **Zero Core modification**: plugins never edit `app/`/`modules/` source.
   All integration is through six existing, unmodified registries
   (Navigation, Theme, Product Type, Payment Gateway, Shipping Carrier,
   Shipping Method Type) plus standard Laravel `loadRoutesFrom()`/
   `loadViewsFrom()`/`loadMigrationsFrom()`/`loadTranslationsFrom()`. No
   Page Builder hook/slot mechanism and no AI/MCP tool registration exist in
   this codebase — do not design a plugin around either.
2. **Platform-level enablement only**: a plugin is enabled/disabled for the
   whole platform, not per-tenant/per-store, in this phase (ADR-0133). A
   single `plugins` table (no `plugin_enablements`) carries lifecycle state,
   keyed by a real, non-nullable PostgreSQL `UNIQUE` constraint on
   `plugin_id`.
3. **Manifest standard**: every plugin ships `plugin.json`, validated on
   every read by `PluginManifest::fromArray()` — see
   `docs/plugins/manifest-reference.md` for the full field reference,
   reserved-namespace rules (`App\`, `Modules\`, `Illuminate\`, `Laravel\`),
   and the mandatory `plugin.<id>.<action>` permission naming convention.
4. **No process-level sandboxing claim**: plugins run in the same PHP
   process/trust boundary as Core. Risk is reduced through capability
   approval, signature verification, and lifecycle controls — never describe
   this as sandboxing or as enforcing filesystem/network access boundaries,
   since no such enforcement exists. See `docs/plugins/security-trust-model.md`.
5. **Composer dependencies ship pre-built**: the platform never runs
   `composer install`/`require` as a lifecycle side effect. A plugin bundles
   its own genuine `vendor/autoload.php`; `PluginComposerDependencyChecker`
   hard-blocks `enable` on a same-package version collision between two
   enabled plugins.
6. **Lifecycle**: `discovered → installed → enabled ⇄ disabled`, plus
   `failed`. Install never auto-enables. Disable never touches data — only
   `uninstall --purge-data` does. See `docs/plugins/lifecycle.md` for the
   full state model and the update-atomicity protocol (ADR-0136).

## Pre-Execution Checklist
- [ ] Does the plugin's manifest pass `PluginManifest::fromArray()` validation (namespace, entrypoint, permission prefix)?
- [ ] Does the plugin integrate only through the six existing registries and standard Laravel loaders — no new registry, no Core edit?
- [ ] Are requested capabilities/permissions declared honestly in `plugin.json` for admin approval, with no filesystem/network sandboxing implied?
- [ ] Do queued jobs/listeners declare `PluginJobMiddleware`?

## Forbidden Shortcuts
- ❌ Editing files in `app/` or `modules/` from a plugin.
- ❌ Bypassing manifest validation, capability approval, or signature/trust checks.
- ❌ Claiming filesystem/network sandboxing, a marketplace trust chain, or PHP dependency isolation that isn't actually enforced.
- ❌ Designing around Page Builder blocks, MCP tools, storefront-facing plugin routes, or tenant/store-scoped enablement — none exist in this phase.
- ❌ Running `composer install`/`require` from any lifecycle code path.

## Validation Steps
1. Manifest schema validation, including reserved-namespace and permission-prefix rejection (`tests/Unit/Plugin/PluginManifestTest.php`).
2. Full lifecycle (`tests/Feature/Plugin/PluginLifecycleServiceTest.php`): install never auto-enables; disable/plain-uninstall preserve data; only `--purge-data` removes it.
3. Registry integration (`tests/Feature/Plugin/PluginRegistryIntegrationTest.php`): a disabled plugin contributes zero entries to a real registry on the next boot cycle.
4. ZIP/signature security (`tests/Feature/Plugin/PluginZipSecurityTest.php`, `PluginPackageIntegrityTest.php`): Zip Slip, symlink, archive-bomb, tampered/unsigned/invalid-signature rejection.
5. Update atomicity failure injection (`tests/Feature/Plugin/PluginUpdateAtomicityTest.php`): migration-succeeds/code-swap-fails recovers the previous version; rollback-also-fails fails closed.
