<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Models\Attribute;
use Modules\Catalog\Models\AttributeTranslation;

class AttributeManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $type = 'text';

    public ?int $editingId = null;

    public string $editCode = '';

    public string $editName = '';

    public string $editType = 'text';

    public ?int $confirmArchiveId = null;

    public function createAttribute(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string'],
        ]);

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        /** @var Attribute $attr */
        $attr = Attribute::create([
            'tenant_id' => (int) $tenantId,
            'code' => $this->code,
            'type' => $this->type,
            'status' => 'active',
        ]);

        AttributeTranslation::create([
            'attribute_id' => $attr->id,
            'locale' => app()->getLocale(),
            'name' => $this->name,
        ]);

        $this->reset(['code', 'name', 'type']);
        session()->flash('success', 'Attribute created.');
    }

    public function editAttribute(int $id): void
    {
        if (! auth()->user()?->can('attributes.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        $attribute = Attribute::where('tenant_id', $tenantId)->findOrFail($id);
        $trans = $attribute->translation();

        $this->editingId = $attribute->id;
        $this->editCode = $attribute->code;
        $this->editName = $trans !== null ? $trans->name : '';
        $this->editType = $attribute->type;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editCode', 'editName', 'editType']);
    }

    public function updateAttribute(): void
    {
        if (! auth()->user()?->can('attributes.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->editingId === null) {
            return;
        }

        $this->validate([
            'editCode' => ['required', 'string', 'max:100'],
            'editName' => ['required', 'string', 'max:255'],
            'editType' => ['required', 'string'],
        ]);

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        $attribute = Attribute::where('tenant_id', $tenantId)->findOrFail($this->editingId);

        $attribute->update([
            'code' => $this->editCode,
            'type' => $this->editType,
        ]);

        AttributeTranslation::updateOrCreate(
            ['attribute_id' => $attribute->id, 'locale' => app()->getLocale()],
            ['name' => $this->editName]
        );

        $this->reset(['editingId', 'editCode', 'editName', 'editType']);
        session()->flash('success', 'Attribute updated.');
    }

    public function openArchiveConfirm(int $id): void
    {
        $this->confirmArchiveId = $id;
    }

    public function cancelArchiveConfirm(): void
    {
        $this->confirmArchiveId = null;
    }

    public function archiveAttribute(): void
    {
        if (! auth()->user()?->can('attributes.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->confirmArchiveId === null) {
            return;
        }

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        $attribute = Attribute::where('tenant_id', $tenantId)->findOrFail($this->confirmArchiveId);
        $attribute->update(['status' => 'archived']);

        $this->confirmArchiveId = null;
        session()->flash('success', 'Attribute archived.');
    }

    public function render(): View|Factory
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        return view('catalog::livewire.attribute-manager', [
            'attributes' => Attribute::where('tenant_id', $tenantId)->where('status', '!=', 'archived')->with(['translations', 'options'])->get(),
        ])->layout('layouts.control-center', ['title' => 'Catalog Attributes']);
    }
}
