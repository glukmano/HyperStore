<?php

declare(strict_types=1);

namespace Plugins\HelloWorldPlugin;

use App\Core\Context\Middleware\ResolveContextMiddleware;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use App\Core\Plugin\PluginServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Phase-16 Plugin SDK reference/fixture plugin. Exercises exactly the
 * extension points the SDK provides — no commerce logic. See
 * docs/plugins/example-plugin-guide.md.
 */
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

        // Harmless scheduled-task proof (Owner Delta / plan §22): registered
        // from boot(), which reruns on every `artisan schedule:run` boot —
        // a disabled plugin's boot() never runs, so this entry evaporates
        // for free on the next scheduler tick (see ADR-0133).
        $this->app->make(Schedule::class)->call(function (): void {
            Log::info('hello-world-plugin: scheduled tick executed.');
        })->name('hello-world-plugin-tick')->daily();
    }
}
