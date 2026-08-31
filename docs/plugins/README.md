# Plugin SDK & Extension System Documentation

This directory contains documentation for developing, packaging, installing, and securing third-party and first-party plugins for the **Hyper Commerce Platform**.

## Plugin SDK Principles

1. **Zero Core Modification**: Plugins integrate strictly through contracts, event listeners, hook registries, and published interfaces.
2. **Strict Manifests**: Every plugin must include a valid `plugin.json` manifest specifying dependencies, permissions, routes, and migrations.
3. **Sandboxed Capabilities**: Plugins are granted explicit permissions and cannot bypass platform security policies.
4. **Lifecycle Management**: Clean installation, activation, deactivation, migration, rollback, and uninstallation.

## Extension Points

- Custom Product Types
- Payment Gateways & Split-Payment Adapters
- Shipping Carriers & Rate Calculators
- Tax Calculation Engines
- Dropshipping & Supplier Connectors
- Page Builder Blocks & UI Widgets
- AI Agents & MCP Tool Registrations
