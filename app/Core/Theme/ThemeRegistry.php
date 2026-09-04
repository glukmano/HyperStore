<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Theme\Contracts\ThemeRegistryInterface;
use App\Core\Theme\DTOs\ThemeManifest;

final class ThemeRegistry implements ThemeRegistryInterface
{
    /** @var array<string, ThemeManifest> */
    private array $themes = [];

    public function register(ThemeManifest $manifest): void
    {
        $this->themes[$manifest->name] = $manifest;
    }

    public function has(string $name): bool
    {
        return isset($this->themes[$name]);
    }

    public function get(string $name): ?ThemeManifest
    {
        return $this->themes[$name] ?? null;
    }

    public function all(): array
    {
        return $this->themes;
    }
}
