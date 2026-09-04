# Example Plugin Walkthrough: `hello-world-plugin`

`plugins/hello-world-plugin/` is the real, working proof plugin built and
installed during Phase-16 implementation — not a hand-wavy sample. It
exercises exactly the extension points the SDK provides, with no commerce
logic, and was installed through the real `plugin:install`/`plugin:enable`
CLI pipeline (not hand-placed on disk).

## Manifest (`plugin.json`)

```json
{
  "manifest_version": 1,
  "id": "hello-world-plugin",
  "name": "Hello World Plugin",
  "version": "1.0.0",
  "author": "HyperStore",
  "license": "MIT",
  "description": "Reference/fixture plugin proving the Phase-16 Plugin SDK: navigation registration, a Control Center page, a seeded permission, a translation string, and a scheduled task. No commerce logic.",
  "compatibility": { "platform": "*", "php": "^8.4" },
  "dependencies": {},
  "requested_permissions": ["plugin.hello-world-plugin.view"],
  "capabilities": ["control_center_navigation", "scheduled_tasks"],
  "entrypoint": "Plugins\\HelloWorldPlugin\\HelloWorldPluginServiceProvider",
  "namespace": "Plugins\\HelloWorldPlugin",
  "migrations": "database/migrations",
  "settings_schema": {}
}
```

## Entrypoint (`src/HelloWorldPluginServiceProvider.php`)

```php
class HelloWorldPluginServiceProvider extends PluginServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom($this->getPath().'/resources/views', 'hello-world-plugin');
        $this->loadTranslationsFrom($this->getPath().'/resources/lang', 'hello-world-plugin');

        Route::middleware(['web', 'auth', ResolveContextMiddleware::class])
            ->prefix('control-center/plugin/hello-world-plugin')
            ->name('control-center.plugin.hello-world-plugin.')
            ->group(function (): void {
                Route::get('/', function () {
                    if (! auth()->user()?->can('plugin.hello-world-plugin.view') && ! auth()->user()?->is_super_admin) {
                        abort(403, 'Permission denied.');
                    }

                    return view('hello-world-plugin::hello')
                        ->layout('layouts.control-center', ['title' => __('hello-world-plugin::hello.title')]);
                })->name('index');
            });

        $this->app->make(NavigationRegistryInterface::class)->register(new NavigationItem(
            key: 'plugin-hello-world-plugin',
            label: 'Hello World Plugin',
            routeName: 'control-center.plugin.hello-world-plugin.index',
            group: 'Plugins',
            permission: 'plugin.hello-world-plugin.view',
            context: 'tenant',
            icon: '👋',
            order: 100,
        ));

        $this->app->make(Schedule::class)->call(function (): void {
            Log::info('hello-world-plugin: scheduled tick executed.');
        })->name('hello-world-plugin-tick')->daily();
    }
}
```

## What this proves, extension point by extension point

| Extension point | Where in the plugin | What it demonstrates |
|---|---|---|
| Real Composer autoloading | `vendor/autoload.php` (generated via `composer install`, no dependencies) | The plugin ships its own genuine autoloader — nothing hand-rolled (Owner Delta #3) |
| Manifest namespace/entrypoint validation | `namespace: "Plugins\\HelloWorldPlugin"`, `entrypoint` inside it | Passes the reserved-namespace and entrypoint-ownership checks in `PluginManifest::fromArray()` |
| Control Center route + view | `Route::get('/', ...)`, `resources/views/hello.blade.php` | A plugin-owned admin screen, gated by its own permission, rendered through the existing `layouts.control-center` shell — no custom design |
| Navigation registration | `NavigationRegistryInterface::register()` | Zero modification to the existing `NavigationRegistry` — same call any Core/Module provider makes |
| Permission naming convention | `plugin.hello-world-plugin.view` | Demonstrates the mandatory `plugin.<id>.<action>` prefix, seeded via the existing `Permission::firstOrCreate()` pattern |
| Translations | `resources/lang/en/hello.php`, `loadTranslationsFrom()` | Standard Laravel translation loading, no plugin-specific mechanism needed |
| Scheduled task | `Schedule::call(...)->daily()` | Proves the free-disable guarantee applies to scheduled tasks too, under the `schedule:run` operational model documented in [lifecycle.md](lifecycle.md) |

## What it deliberately does not touch

Product Types, Payment Gateways, and Shipping registries are **not**
exercised by this plugin — those integration points are proven directly by
`tests/Feature/Plugin/PluginRegistryIntegrationTest.php` and the
architecture tests against the real registries, which is the stronger
evidence boundary than routing a demo plugin through them.

## Reproducing it yourself

```bash
# From a plugin source directory containing plugin.json, composer.json, src/...
composer install --working-dir=plugins-src/hello-world-plugin --no-dev --optimize-autoloader
cd plugins-src/hello-world-plugin && zip -r ../hello-world-plugin.zip . -x '.git/*' && cd -

php artisan plugin:install plugins-src/hello-world-plugin.zip
php artisan plugin:enable hello-world-plugin --approve-permissions
php artisan plugin:list
```
