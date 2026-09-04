<?php

declare(strict_types=1);

namespace Plugins\TestFixturePlugin;

use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use App\Core\Plugin\PluginServiceProvider;

class TestFixturePluginServiceProvider extends PluginServiceProvider
{
    public function boot(): void
    {
        $this->app->make(NavigationRegistryInterface::class)->register(new NavigationItem(
            key: 'plugin-test-fixture-plugin',
            label: 'Test Fixture Plugin',
            routeName: 'control-center.dashboard',
            group: 'Plugins',
            context: 'all',
        ));
    }
}
