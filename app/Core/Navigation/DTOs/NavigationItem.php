<?php

declare(strict_types=1);

namespace App\Core\Navigation\DTOs;

use Illuminate\Support\Facades\Route;

/**
 * A single Control Center sidebar entry contributed by a Core module, first-party
 * Module, or (in a future phase) a Plugin through the exact same registration contract.
 */
final readonly class NavigationItem
{
    public function __construct(
        public string $key,
        public string $label,
        public string $routeName,
        public string $group,
        public ?string $permission = null,
        public string $context = 'tenant',
        public ?string $icon = null,
        public int $order = 100,
    ) {}

    /**
     * @param  array<string, mixed>  $routeParameters
     */
    public function url(array $routeParameters = []): ?string
    {
        return Route::has($this->routeName)
            ? route($this->routeName, $routeParameters)
            : null;
    }
}
