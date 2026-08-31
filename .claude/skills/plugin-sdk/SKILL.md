---
name: plugin-sdk
description: Enforces first-party Plugin SDK, manifest specifications, permission boundaries, hook lifecycle, and Core isolation. Use when designing the plugin system or creating plugins.
---

# Plugin SDK & Extension Architecture

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 3.3, 21, 26)

## Core Rules & Mandates

1. **Zero Core Modification**:
   - Plugins MUST NEVER modify Core application source code directly.
   - All integrations occur via extension contracts, event listeners, hook registries, and published APIs.
2. **Plugin Manifest Standard**:
   - Every plugin requires a structured `plugin.json` manifest specifying:
     - Plugin ID, Name, Version, Author, License.
     - Platform compatibility constraints.
     - Declared dependencies, migrations, and requested permissions.
     - Provided extension points (Product Types, Payment Gateways, Shipping Carriers, Page Builder blocks, MCP tools).
3. **Permission & Security Sandboxing**:
   - Plugins declare requested permissions upon installation.
   - Platform verifies and enforces permission boundaries (e.g. filesystem access, outbound network access, database writes).
4. **Lifecycle & Migrations**:
   - Support clean lifecycle: Install, Enable, Disable, Update, Uninstall.
   - Plugin migrations must be encapsulated under the plugin folder and properly rolled back on uninstall.

## Pre-Execution Checklist
- [ ] Does the plugin contain a valid `plugin.json` manifest?
- [ ] Does the plugin communicate exclusively through registered interfaces and hooks?
- [ ] Are plugin migration rollbacks non-destructive to core database tables?

## Forbidden Shortcuts
- ❌ Editing files in `app/` or `modules/` from a plugin.
- ❌ Bypassing the plugin manifest or permission declarations.
- ❌ Running unverified direct SQL statements outside module contracts.

## Validation Steps
1. Test plugin manifest schema validation.
2. Test full plugin lifecycle (install -> activate -> run hook -> deactivate -> uninstall).
3. Verify that disabling a plugin gracefully detaches its hooks without system failure.
