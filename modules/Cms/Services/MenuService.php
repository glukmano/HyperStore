<?php

declare(strict_types=1);

namespace Modules\Cms\Services;

use Modules\Cms\Models\Menu;
use Modules\Cms\Models\MenuItem;

final class MenuService
{
    public function findOrCreate(int $tenantId, string $key): Menu
    {
        return Menu::query()->firstOrCreate(['tenant_id' => $tenantId, 'key' => $key]);
    }

    public function addItem(Menu $menu, string $routeType, string $routeTarget, string $label, string $locale = 'en', ?int $parentId = null): MenuItem
    {
        $nextPosition = (int) $menu->allItems()->max('sort_order') + 1;

        $item = $menu->allItems()->create([
            'parent_id' => $parentId,
            'route_type' => $routeType,
            'route_target' => $routeTarget,
            'sort_order' => $nextPosition,
        ]);

        $item->translations()->create(['locale' => $locale, 'label' => $label]);

        return $item;
    }
}
