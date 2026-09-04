<?php

declare(strict_types=1);

namespace App\Core\Plugin\Livewire;

use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

class PluginDetail extends Component
{
    public string $pluginId;

    public bool $confirmingUninstall = false;

    public bool $purgeDataOnUninstall = false;

    public function mount(string $pluginId): void
    {
        if (! auth()->user()?->can('plugins.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->pluginId = $pluginId;
    }

    public function approvePermissions(PluginLifecycleService $lifecycle): void
    {
        $this->authorizeManage();

        try {
            $lifecycle->approvePermissions($this->pluginId);
            session()->flash('success', 'Capabilities approved.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function enable(PluginLifecycleService $lifecycle): void
    {
        $this->authorizeManage();

        try {
            $lifecycle->enable($this->pluginId, approvePermissions: true);
            session()->flash('success', 'Plugin enabled.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function disable(PluginLifecycleService $lifecycle): void
    {
        $this->authorizeManage();

        try {
            $lifecycle->disable($this->pluginId);
            session()->flash('success', 'Plugin disabled.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openUninstallConfirm(): void
    {
        $this->confirmingUninstall = true;
    }

    public function cancelUninstallConfirm(): void
    {
        $this->confirmingUninstall = false;
        $this->purgeDataOnUninstall = false;
    }

    public function uninstall(PluginLifecycleService $lifecycle): void
    {
        $this->authorizeManage();

        try {
            $lifecycle->uninstall($this->pluginId, $this->purgeDataOnUninstall);
            session()->flash('success', 'Plugin uninstalled.');
            $this->redirect(route('control-center.platform.plugins.index'), navigate: true);
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
            $this->confirmingUninstall = false;
        }
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('plugins.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $plugin = Plugin::query()->where('plugin_id', $this->pluginId)->firstOrFail();

        return view('livewire.control-center.plugins.plugin-detail', [
            'plugin' => $plugin,
        ])->layout('layouts.control-center', ['title' => 'Plugin: '.$plugin->name]);
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('plugins.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
