<?php

declare(strict_types=1);

namespace App\Core\Plugin\Livewire;

use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class PluginList extends Component
{
    use WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $packageFile = null;

    public ?string $installError = null;

    public function installPackage(PluginLifecycleService $lifecycle): void
    {
        if (! auth()->user()?->can('plugins.install') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->installError = null;

        $this->validate(['packageFile' => ['required', 'file', 'mimes:zip', 'max:51200']]);

        if ($this->packageFile === null) {
            return;
        }

        try {
            $plugin = $lifecycle->install($this->packageFile->getRealPath());
            $this->reset('packageFile');
            session()->flash('success', "Plugin [{$plugin->plugin_id}] installed. Review and enable it from its detail page.");
            $this->redirect(route('control-center.platform.plugins.show', ['pluginId' => $plugin->plugin_id]), navigate: true);
        } catch (Throwable $e) {
            $this->installError = $e->getMessage();
        }
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('plugins.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        return view('livewire.control-center.plugins.plugin-list', [
            'plugins' => Plugin::query()->orderBy('plugin_id')->get(),
        ])->layout('layouts.control-center', ['title' => 'Plugins']);
    }
}
