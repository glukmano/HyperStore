<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\PluginServiceProvider;

interface PluginRegistryInterface
{
    public function register(PluginServiceProvider $provider): void;

    /**
     * @return list<PluginServiceProvider>
     */
    public function all(): array;

    /**
     * Enabled plugins only, in dependency-safe (topologically sorted) order.
     *
     * @return list<PluginServiceProvider>
     */
    public function getOrdered(): array;

    public function isEnabled(string $pluginId): bool;
}
