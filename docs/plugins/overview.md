# Plugin SDK Overview

## What a plugin is

A plugin is a self-contained package under `plugins/<plugin-id>/` consisting
of a manifest (`plugin.json`), a PHP entrypoint class extending
`App\Core\Plugin\PluginServiceProvider`, and its own pre-built Composer
`vendor/` directory. Plugins are discovered, registered, and booted by
`App\Core\Plugin\PluginKernel` — a system deliberately structurally
independent from `App\Core\Modular\ModuleKernel` (see ADR-0133), sequenced to
boot strictly *after* all Modules from `AppServiceProvider::boot()`.

## Directory layout

```
plugins/
  <plugin-id>/                       slug: lowercase letters/digits/hyphens only
    plugin.json                      manifest (see manifest-reference.md)
    plugin.sig                       optional signature (see security-trust-model.md)
    composer.json
    vendor/                          the plugin's OWN pre-built Composer autoloader
      autoload.php
      composer/installed.json
    src/
      <Entrypoint>ServiceProvider.php
    database/
      migrations/
    resources/
      views/
      lang/
```

`plugin_id` (the manifest's `id` field) must equal the directory name. A
plugin's ZIP package is validated and staged before ever touching this
directory — see [security-trust-model.md](security-trust-model.md).

## Discovery, registration, and boot

On every HTTP request (there is no Octane in this deployment, so
`AppServiceProvider::boot()` reruns every request), `PluginKernel` runs three
phases:

1. **`discover()`** — scans `plugins/*/plugin.json`. For each directory, reads
   and validates the manifest, and skips it unless the platform's `plugins`
   table has a row for that `plugin_id` with `status = 'enabled'`. If enabled,
   it `require_once`s the plugin's own `vendor/autoload.php` (never a
   hand-rolled autoloader — see [manifest-reference.md](manifest-reference.md)),
   instantiates the manifest's `entrypoint` class, and registers it into a
   `PluginRegistry`.
2. **`registerPlugins()`** — calls `register()` on every discovered plugin's
   service provider, in dependency order (a Kahn's-algorithm topological sort
   over each plugin's `dependencies` map, computed at runtime from each
   plugin's stored manifest — independent from `ModuleRegistry`'s own sort,
   deliberately not shared, since Module and Plugin failure/trust semantics
   differ).
3. **`bootPlugins()`** — calls `boot()` on every plugin, same order.

Each individual plugin's `register()`/`boot()` call is wrapped in isolation: a
thrown exception is logged via the existing `AuditManagerInterface` (no new
audit subsystem), the plugin's `consecutive_boot_failures` counter increments,
and the plugin auto-disables after `config('plugins.max_consecutive_boot_failures')`
consecutive failures (default 3) — one broken plugin never crashes the
platform or the other plugins.

## The per-request rebuild invariant

Because `PluginKernel::bootPlugins()` reruns on every request, a disabled
plugin's `register()`/`boot()` simply never executes on the next request —
its contributions to Navigation, Theme, Product Type, Payment Gateway,
Shipping Carrier, and Shipping Method Type registries evaporate for free, with
**zero ownership-tracking code** added to any of those six registries. This is
a load-bearing architectural invariant (ADR-0133), proven by
`tests/Feature/Plugin/PluginRegistryIntegrationTest.php`, which reruns a fresh
`PluginKernel` + fresh registry cycle and asserts a disabled plugin's entries
are absent.

**Operational caveat:** this guarantee depends on the deployment using
cron-invoked `artisan schedule:run` (which reboots the full application,
including `PluginKernel`, every tick) rather than a long-running
`artisan schedule:work` process, and on route caches being cleared after any
plugin lifecycle change (`PluginLifecycleService` does this automatically via
`Artisan::call('route:clear')` whenever `app()->routesAreCached()`).

## Registries plugins may extend (reused, never duplicated)

| Registry | Contract | Reused by plugins for |
|---|---|---|
| Navigation | `NavigationRegistryInterface::register()` | Control Center menu entries |
| Theme | `ThemeRegistryInterface::register()` | Registering an entirely new storefront theme |
| Product Type | `ProductTypeRegistryInterface::register()` | A new sellable product type |
| Payment Gateway | `PaymentGatewayRegistryInterface::register()` | A new payment provider |
| Shipping Carrier | `CarrierRegistry::register()` | A new shipping carrier |
| Shipping Method Type | `ShippingMethodTypeRegistry::register()` | A new shipping rate method |

Zero modifications were made to any of these six registries' internals to
support plugins — plugins call the exact same `register()` methods Core and
Module service providers already call.

## Not built in this phase

Tax provider registry, Supplier connector registry, and Fulfillment packing
strategy registry do not exist in this codebase (each is a single binding
today) and were not built for Phase-16 — there is no accepted ADR committing
to them and no second implementation to justify a registry. See the phase
file's scope boundaries for the full list of exclusions.
