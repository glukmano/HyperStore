<?php

declare(strict_types=1);

namespace App\Core\Theme\Contracts;

use App\Core\Theme\DTOs\ThemeManifest;

/**
 * Single registration contract for known themes.
 *
 * Today: the built-in `themes/default` theme is registered from AppServiceProvider::boot().
 * Future: the Plugin SDK (or a theme-install flow) registers additional themes through this
 * exact same contract — no Phase-15-only registration path exists that would need to be
 * redesigned when that capability arrives.
 */
interface ThemeRegistryInterface
{
    public function register(ThemeManifest $manifest): void;

    public function has(string $name): bool;

    public function get(string $name): ?ThemeManifest;

    /**
     * @return array<string, ThemeManifest>
     */
    public function all(): array;
}
