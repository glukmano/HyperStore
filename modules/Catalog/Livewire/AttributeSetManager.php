<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Models\Attribute;
use Modules\Catalog\Models\AttributeSet;

class AttributeSetManager extends Component
{
    public string $name = '';

    public string $code = '';

    /** @var array<int, int> */
    public array $selectedAttributes = [];

    public ?int $editingId = null;

    public string $editName = '';

    public string $editCode = '';

    /** @var array<int, int> */
    public array $editSelectedAttributes = [];

    public ?int $confirmArchiveId = null;

    public function createSet(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        /** @var AttributeSet $set */
        $set = AttributeSet::create([
            'tenant_id' => $tenantId,
            'name' => $this->name,
            'code' => $this->code,
            'status' => 'active',
        ]);

        if (! empty($this->selectedAttributes)) {
            $set->attributes()->sync($this->selectedAttributes);
        }

        $this->reset(['name', 'code', 'selectedAttributes']);
        session()->flash('success', 'Attribute Set created.');
    }

    public function editSet(int $id): void
    {
        if (! auth()->user()?->can('attribute_sets.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        $set = AttributeSet::where('tenant_id', $tenantId)->with('attributes')->findOrFail($id);

        $this->editingId = $set->id;
        $this->editName = $set->name;
        $this->editCode = $set->code;
        $this->editSelectedAttributes = $set->attributes->pluck('id')->all();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editName', 'editCode', 'editSelectedAttributes']);
    }

    public function updateSet(): void
    {
        if (! auth()->user()?->can('attribute_sets.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->editingId === null) {
            return;
        }

        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editCode' => ['required', 'string', 'max:100'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        $set = AttributeSet::where('tenant_id', $tenantId)->findOrFail($this->editingId);

        $set->update([
            'name' => $this->editName,
            'code' => $this->editCode,
        ]);

        $set->attributes()->sync($this->editSelectedAttributes);

        $this->reset(['editingId', 'editName', 'editCode', 'editSelectedAttributes']);
        session()->flash('success', 'Attribute Set updated.');
    }

    public function openArchiveConfirm(int $id): void
    {
        $this->confirmArchiveId = $id;
    }

    public function cancelArchiveConfirm(): void
    {
        $this->confirmArchiveId = null;
    }

    public function archiveSet(): void
    {
        if (! auth()->user()?->can('attribute_sets.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->confirmArchiveId === null) {
            return;
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        $set = AttributeSet::where('tenant_id', $tenantId)->findOrFail($this->confirmArchiveId);
        $set->update(['status' => 'archived']);

        $this->confirmArchiveId = null;
        session()->flash('success', 'Attribute Set archived.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('catalog::livewire.attribute-set-manager', [
            'sets' => AttributeSet::where('tenant_id', $tenantId)->where('status', '!=', 'archived')->with(['groups', 'attributes'])->get(),
            'allAttributes' => Attribute::where('tenant_id', $tenantId)->where('status', 'active')->get(),
        ])->layout('layouts.control-center', ['title' => 'Attribute Sets']);
    }
}
