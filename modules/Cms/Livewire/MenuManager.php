<?php

declare(strict_types=1);

namespace Modules\Cms\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cms\Models\Menu;
use Modules\Cms\Services\MenuService;

class MenuManager extends Component
{
    public string $menuKey = 'main';

    public string $label = '';

    public string $routeType = 'external';

    public string $routeTarget = '';

    public function addItem(MenuService $service): void
    {
        $this->authorizeManage();

        $this->validate([
            'label' => 'required|string|max:150',
            'routeType' => 'required|in:page,category,product,external,vendor',
            'routeTarget' => 'required|string|max:255',
        ]);

        $menu = $service->findOrCreate($this->tenantId(), $this->menuKey);
        // Phase-18 §3: active-Locale-driven, never hardcoded.
        $service->addItem($menu, $this->routeType, $this->routeTarget, $this->label, app()->getLocale());

        $this->reset(['label', 'routeTarget']);
        session()->flash('success', 'Menu item added.');
    }

    public function render(MenuService $service): View|Factory
    {
        $this->authorizeManage();

        $menu = $service->findOrCreate($this->tenantId(), $this->menuKey);
        $menu->load('allItems.translations');

        $menus = Menu::query()->where('tenant_id', $this->tenantId())->pluck('key');

        return view('livewire.control-center.cms.menu-manager', ['menu' => $menu, 'menus' => $menus])
            ->layout('layouts.control-center', ['title' => 'Menus']);
    }

    private function tenantId(): int
    {
        return (int) app(ContextManager::class)->getTenant()->getId();
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('cms.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
