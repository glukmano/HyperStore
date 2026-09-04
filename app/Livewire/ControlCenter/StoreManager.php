<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Stores\Contracts\StoreCreationServiceInterface;
use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\ControlCenterMutationExecutorInterface;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;

class StoreManager extends Component
{
    public string $name = '';

    public string $slug = '';

    public ?int $editingStoreId = null;

    public string $editingActiveTheme = '';

    public function createStore(StoreCreationServiceInterface $storeService, ControlCenterMutationExecutorInterface $executor): void
    {
        if (! auth()->user()?->can('stores.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        /** @var array<string, mixed> $validated */
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $tenantId = $this->currentTenantId();

        $request = request();

        $executor->execute($request, 'create_store', function (int $effectiveUserId) use ($tenantId, $validated, $storeService) {
            return $storeService->createStore($tenantId, $validated, $effectiveUserId);
        });

        $this->reset(['name', 'slug']);
        session()->flash('success', 'Store created successfully.');
    }

    public function editTheme(int $storeId): void
    {
        if (! auth()->user()?->can('stores.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->currentTenantId();

        $store = Store::where('tenant_id', $tenantId)->findOrFail($storeId);
        $this->editingStoreId = $store->id;
        $this->editingActiveTheme = $store->active_theme;
    }

    public function saveTheme(): void
    {
        if (! auth()->user()?->can('stores.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->editingStoreId === null) {
            return;
        }

        $this->validate([
            'editingActiveTheme' => ['nullable', 'string', 'max:100'],
        ]);

        $tenantId = $this->currentTenantId();

        $store = Store::where('tenant_id', $tenantId)->findOrFail($this->editingStoreId);
        $store->update(['active_theme' => $this->editingActiveTheme]);

        $this->editingStoreId = null;
        $this->editingActiveTheme = '';
        session()->flash('success', 'Store theme updated.');
    }

    public function cancelEdit(): void
    {
        $this->editingStoreId = null;
        $this->editingActiveTheme = '';
    }

    private function currentTenantId(): int
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context required.');
        }

        return (int) $tenantId;
    }

    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();

        $stores = $tenantId !== null
            ? Store::where('tenant_id', (int) $tenantId)->orderByDesc('id')->get()
            : collect();

        return view('livewire.control-center.store-manager', [
            'stores' => $stores,
        ])->layout('layouts.control-center', ['title' => 'Stores']);
    }
}
