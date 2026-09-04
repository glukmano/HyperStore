<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\SuperAdmin\Contracts\ControlCenterMutationExecutorInterface;
use App\Core\SuperAdmin\Contracts\PlatformSettingsServiceInterface;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Platform settings screen.
 *
 * Note: PlatformSettingsServiceInterface exposes only get(string $key, mixed $default)
 * and set(string $key, mixed $value, bool $encrypt, ?int $userId). It has no
 * list/all() method, so this screen intentionally only provides the set-form;
 * no listing table is built here (per Owner-mandated instructions: do not invent
 * an interface method that does not exist).
 */
class TenantSettingsManager extends Component
{
    public string $key = '';

    public string $value = '';

    public bool $is_encrypted = false;

    public ?string $lastLookupKey = null;

    public ?string $lastLookupValue = null;

    public function setSetting(PlatformSettingsServiceInterface $settingsService, ControlCenterMutationExecutorInterface $executor): void
    {
        if (! auth()->user()?->can('settings.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $validated = $this->validate([
            'key' => ['required', 'string', 'max:100'],
            'value' => ['required', 'string'],
            'is_encrypted' => ['boolean'],
        ]);

        /** @var User $user */
        $user = auth()->user();

        $request = request();

        $executor->executeSuperAdmin($request, fn () => $settingsService->set(
            $validated['key'],
            $validated['value'],
            (bool) $validated['is_encrypted'],
            $user->id
        ));

        $this->reset(['key', 'value', 'is_encrypted']);
        session()->flash('success', 'Setting saved successfully.');
    }

    public function lookupSetting(PlatformSettingsServiceInterface $settingsService): void
    {
        if (! auth()->user()?->can('settings.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->key === '') {
            return;
        }

        $this->lastLookupKey = $this->key;
        $value = $settingsService->get($this->key);
        $this->lastLookupValue = $value === null ? null : (string) $value;
    }

    public function render(): View
    {
        return view('livewire.control-center.tenant-settings-manager')
            ->layout('layouts.control-center', ['title' => 'Platform Settings']);
    }
}
