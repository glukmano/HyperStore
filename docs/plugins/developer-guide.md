# Developer Guide

## Minimal plugin

```
plugins/acme-example-plugin/
  plugin.json
  composer.json
  vendor/                 (from `composer install --working-dir=plugins/acme-example-plugin`)
  src/
    AcmeExamplePluginServiceProvider.php
```

```php
<?php

namespace Plugins\AcmeExamplePlugin;

use App\Core\Plugin\PluginServiceProvider;

class AcmeExamplePluginServiceProvider extends PluginServiceProvider
{
    public function register(): void
    {
        // Container bindings, same as any Laravel ServiceProvider::register().
    }

    public function boot(): void
    {
        // Route/view/translation/migration loading and registry calls go here.
        // Reruns on every request (no Octane) — see overview.md's
        // per-request rebuild invariant. Must be safe to call repeatedly.
    }
}
```

`PluginServiceProvider` extends Laravel's own `Illuminate\Support\ServiceProvider`
directly (not `App\Core\Modular\ModuleServiceProvider`), so
`loadRoutesFrom()`, `loadViewsFrom()`, `loadMigrationsFrom()`, and
`loadTranslationsFrom()` are all available for free. `$this->getPath()`
returns the plugin's own root directory; `$this->getManifest()` returns the
parsed `PluginManifest`.

Build the package for distribution by running
`composer install --no-dev --optimize-autoloader` inside the plugin
directory (generating a real `vendor/autoload.php`) before zipping it —
never rely on the platform to run Composer for you (see
[manifest-reference.md](manifest-reference.md#composer-dependency-loading-owner-delta-3)).

## Registering into an existing registry

Call the exact same contract Core/Module service providers call — no
plugin-specific variant exists:

```php
public function boot(): void
{
    $this->app->make(\App\Core\Navigation\Contracts\NavigationRegistryInterface::class)
        ->register(new \App\Core\Navigation\DTOs\NavigationItem(
            key: 'plugin-acme-example-plugin',
            label: 'Acme Example',
            routeName: 'control-center.plugin.acme-example-plugin.index',
            group: 'Plugins',
            permission: 'plugin.acme-example-plugin.view',
        ));
}
```

The same pattern applies to `ThemeRegistryInterface`,
`ProductTypeRegistryInterface`, `PaymentGatewayRegistryInterface`,
`CarrierRegistry`, and `ShippingMethodTypeRegistry` — see
[overview.md](overview.md#registries-plugins-may-extend-reused-never-duplicated).

## Routes

Control Center (admin) routes only — plugins may not register storefront or
public-facing routes in this phase (ADR-0006's isolation table restricts
plugins to Admin UI extension points):

```php
Route::middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->prefix('control-center/plugin/acme-example-plugin')
    ->name('control-center.plugin.acme-example-plugin.')
    ->group(function (): void {
        Route::get('/', ...)->name('index');
    });
```

Gate every route handler on the plugin's own seeded permission (see below) —
routing does not gate this for you automatically.

## Permissions

Every requested permission in `plugin.json`'s `requested_permissions` must be
prefixed `plugin.<your-plugin-id>.` — enforced at manifest-parse time. Seeded
automatically on `enable` via the existing
`Permission::firstOrCreate(['name' => ..., 'guard_name' => 'web'|'sanctum'])`
pattern. Check them in your own route handlers/Livewire components exactly
as you would any other permission in this codebase
(`auth()->user()->can('plugin.acme-example-plugin.view')`).

There is no permission-ownership tracking table — cleanup on `--purge-data`
uninstall re-reads the plugin's own last-installed manifest snapshot as the
source of truth for which permissions it owns.

## Migrations

```php
public function boot(): void
{
    $this->loadMigrationsFrom($this->getPath().'/database/migrations');
}
```

Migrations only run when the plugin is explicitly `enable`d (see
[lifecycle.md](lifecycle.md#enable)), scoped to the plugin's own migrations
directory. Never modify or reference a Core/Module migration file from a
plugin migration — plugin migrations only ever live under, and are only ever
rolled back from, the plugin's own directory.

## Settings and secrets

There is no platform-level plugin settings table in this phase (enablement
is platform-level only). Store your own plugin's settings in your own
migrated tables. For secret values, follow the existing encrypted-column
precedent used elsewhere in this codebase
(`Modules\Shipping\Models\CarrierCredential`): a dedicated column cast or
wrapped with `Crypt::encryptString()`/`'encrypted'` Eloquent cast, `$hidden`
on the model, never surfaced in Livewire public properties, logs, or audit
payloads.

## Queued jobs and listeners

Every `ShouldQueue` job or listener your plugin dispatches **must** declare
the required middleware:

```php
public function middleware(): array
{
    return [new \App\Core\Plugin\Concerns\PluginJobMiddleware('acme-example-plugin')];
}
```

This checks the plugin's enabled state directly against the `plugins` table
at **execution time** (not the per-request in-memory registry, since a queue
worker is a separate, possibly long-lived process) and silently deletes the
job without running your business logic if the plugin has since been
disabled — closing the "already-queued when disabled" race that the
per-request rebuild guarantee alone does not cover.

## Scheduled tasks

```php
public function boot(): void
{
    $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
        ->call(fn () => /* ... */)
        ->name('acme-example-plugin-tick')
        ->daily();
}
```

No separate console-kernel wiring is needed — `artisan schedule:run` boots
the full application, including `PluginKernel`, exactly like an HTTP request.
**Caveat:** this free-disable guarantee only holds when the deployment uses
cron-invoked `artisan schedule:run`. A long-running `artisan schedule:work`
process boots once and stays resident, so a disabled plugin's schedule entry
would persist until that process restarts.

## Dependencies on other plugins

```json
{ "dependencies": { "some-other-plugin-id": "^1.0" } }
```

Resolved via `PluginRegistry`'s own topological sort at every boot cycle. A
missing dependency or a dependency cycle blocks `enable` with a clear
diagnostic, matching the message style of the existing (unrelated)
`ModuleRegistry`.

## Testing your plugin against this platform

There is no plugin-specific test harness shipped with the SDK. Build and
package your plugin, install it into a local `plugins/` directory in a
development checkout of this platform, and exercise it through the normal
`plugin:install`/`plugin:enable` CLI flow, or write a Pest feature test
following the pattern in `tests/Feature/Plugin/PluginLifecycleServiceTest.php`
and `tests/Support/PluginTestFixtures.php` if you are contributing to the SDK
itself.
