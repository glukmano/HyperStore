# Plugin SDK & Extensibility Platform Documentation

Documentation for the Phase-16 Plugin SDK (`app/Core/Plugin/`) — how plugins are
built, packaged, installed, verified, and operated on the Hyper Commerce
Platform. See [`docs/phases/PHASE-16-PLUGIN-SDK-EXTENSIBILITY-PLATFORM.md`](../phases/PHASE-16-PLUGIN-SDK-EXTENSIBILITY-PLATFORM.md)
for the authoritative phase scope and [ADR-0133](../decisions/ADR-0133-plugin-sdk-kernel-and-identity.md)
through [ADR-0136](../decisions/ADR-0136-plugin-migrations-dependency-and-update-atomicity.md)
for the accepted architecture decisions.

## Documents

| File | Covers |
|---|---|
| [overview.md](overview.md) | What a plugin is, the directory layout, how discovery/boot works |
| [manifest-reference.md](manifest-reference.md) | Every `plugin.json` field, the `plugin.sig` signature contract |
| [lifecycle.md](lifecycle.md) | States, CLI/Control Center actions, migration and update-atomicity behavior |
| [security-trust-model.md](security-trust-model.md) | ZIP extraction security, trust tiers, the honest same-process trust boundary |
| [installation-update.md](installation-update.md) | Step-by-step install/update/uninstall via CLI and Control Center |
| [developer-guide.md](developer-guide.md) | Building a plugin: routes, migrations, permissions, jobs, scheduled tasks |
| [example-plugin-guide.md](example-plugin-guide.md) | Walkthrough of the real `plugins/hello-world-plugin/` reference plugin |

## Plugin SDK Principles (as actually implemented)

1. **Zero Core modification.** Plugins integrate only through existing, unmodified
   registries and contracts (Navigation, Theme, Product Type, Payment Gateway,
   Shipping Carrier, Shipping Method Type) plus standard Laravel
   `loadRoutesFrom()`/`loadViewsFrom()`/`loadMigrationsFrom()`/`loadTranslationsFrom()`.
2. **Platform-level enablement only.** A plugin is enabled or disabled for the
   whole platform — there is no tenant/store-scoped runtime enablement in this
   phase (see ADR-0133). Plugins may still store their own tenant/store business
   data in their own tables.
3. **Strict, validated manifests.** Every plugin ships a `plugin.json` that is
   schema-validated on every read — reserved namespaces, permission naming,
   entrypoint ownership, and manifest version are all enforced at parse time.
4. **No process-level sandboxing.** Plugins run in the same PHP process and
   trust boundary as Core and Modules. Risk is reduced through explicit
   registration contracts, lifecycle controls, capability approval, signature
   verification, and per-plugin error isolation — not through an isolation
   guarantee that does not exist. See [security-trust-model.md](security-trust-model.md).
5. **Lifecycle safety.** Install never auto-enables. Disable never touches
   data. Only `--purge-data` uninstall removes a plugin's own migrated schema.
   Updates never publish a new version until the code swap actually succeeds
   (ADR-0136).

## Explicitly out of scope for this phase

Plugin/Theme Marketplace, Page Builder, AI/MCP agent tooling, storefront-facing
plugin routes, and tenant/store-scoped plugin enablement are not part of this
SDK. See the phase file's "Explicitly Excluded" section for the full list.
