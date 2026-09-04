# Installation & Update

Every action below is available both via CLI (`php artisan plugin:*`) and via
the Control Center screens under `control-center/platform/plugins/*`
(permissions `plugins.view`, `plugins.install`, `plugins.manage` — seeded by
`database/seeders/PluginPermissionSeeder.php`). The CLI and Control Center
both call the same `PluginLifecycleService` methods — there is no behavior
divergence between the two surfaces.

## Install

```
php artisan plugin:install /path/to/acme-example-plugin.zip
```

Validates and stages the package (see
[security-trust-model.md](security-trust-model.md)), then moves it into
`plugins/<id>/` and creates a `plugins` row with `status = installed`. The
plugin is **not** enabled yet — it is not booted, no migrations have run, and
it contributes nothing to any registry.

In the Control Center: **Plugins → Install**, upload the ZIP, review the
validation report (manifest summary, trust tier, requested
capabilities/permissions), then confirm.

## Enable

```
php artisan plugin:enable acme-example-plugin
php artisan plugin:enable acme-example-plugin --approve-permissions
```

`--approve-permissions` is required the first time a plugin is enabled (or
whenever an update requests a **new** capability not previously granted) —
without it, enable fails with a clear "permissions not approved" error
listing what the plugin is asking for. This is a deliberate two-step gate: an
administrator must see and approve what a plugin can do before its code ever
runs.

In the Control Center: the plugin detail screen shows requested capabilities
and permissions with an explicit **Approve & Enable** action.

## Disable

```
php artisan plugin:disable acme-example-plugin
```

Immediate. Never touches data. See [lifecycle.md](lifecycle.md#disable) for
exactly what "disabled" means at runtime.

## Update

```
php artisan plugin:update acme-example-plugin /path/to/acme-example-plugin-1.3.0.zip
```

Runs the full atomicity protocol described in
[lifecycle.md](lifecycle.md#update--the-atomicity-protocol-adr-0136-owner-delta-5).
If the update fails at any point, `plugin:inspect acme-example-plugin` and
`plugin:doctor` both surface the recorded `failure_reason` with exact
diagnostics — including, in the worst case, the on-disk path of a preserved
backup that requires manual review.

## Uninstall

```
php artisan plugin:uninstall acme-example-plugin
php artisan plugin:uninstall acme-example-plugin --purge-data
```

Without `--purge-data`: removes the plugin's code and its `plugins` row.
Database tables and rows the plugin created are left in place — reinstalling
the same plugin later will find its own data still there (its migrations are
already recorded as applied and will not attempt to re-run against
already-existing tables).

With `--purge-data`: additionally rolls back the plugin's own migrations
before removing its files, permanently deleting the plugin's schema and
data. This is the only destructive path in the entire lifecycle and must be
explicitly requested.

## Inspection and diagnostics

```
php artisan plugin:list
php artisan plugin:inspect acme-example-plugin
php artisan plugin:doctor
```

- `plugin:list` — every known plugin, status, trust level, version.
- `plugin:inspect <id>` — full manifest snapshot, granted permissions,
  failure reason (if any), migration batch.
- `plugin:doctor` — runs the same Composer dependency-conflict check used at
  `enable` time across all currently-enabled plugins, plus a per-plugin
  health check, printing exact diagnostics for anything unhealthy. Exits
  non-zero if any plugin is unhealthy (suitable for CI/health-check
  pipelines).

## Route caching

`PluginLifecycleService` calls `Artisan::call('route:clear')` automatically
after `enable`/`disable`/`update`/`uninstall` whenever
`app()->routesAreCached()` is true. If your deployment runs
`artisan route:cache` as part of its own deploy process, re-run it after any
plugin lifecycle change — the automatic clear prevents a stale cached route
table, but does not re-cache for you.
