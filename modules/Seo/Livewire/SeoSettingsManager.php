<?php

declare(strict_types=1);

namespace Modules\Seo\Livewire;

use App\Core\Context\ContextManager;
use App\Core\Stores\Models\Store;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Edits the one real per-store SEO setting found in the codebase —
 * Store.settings['block_search_engines'], already consumed by
 * Modules\Seo\Services\RobotsService. No parallel settings store invented.
 */
class SeoSettingsManager extends Component
{
    public bool $blockSearchEngines = false;

    public function mount(): void
    {
        $this->authorizeManage();
        $store = $this->store();
        $this->blockSearchEngines = (bool) ($store->settings['block_search_engines'] ?? false);
    }

    public function save(): void
    {
        $this->authorizeManage();
        $store = $this->store();
        $settings = $store->settings ?? [];
        $settings['block_search_engines'] = $this->blockSearchEngines;
        $store->settings = $settings;
        $store->save();

        session()->flash('success', 'SEO settings saved.');
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        return view('livewire.control-center.seo.seo-settings-manager')
            ->layout('layouts.control-center', ['title' => 'SEO Settings']);
    }

    private function store(): Store
    {
        $storeId = (int) app(ContextManager::class)->getStore()->getId();

        return Store::query()->findOrFail($storeId);
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('seo.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
