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

    public function render(): View|Factory
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        return view('catalog::livewire.attribute-manager', [
            'attributes' => Attribute::where('tenant_id', $tenantId)->with(['translations', 'options'])->get(),
        ])->layout('layouts.control-center', ['title' => 'Catalog Attributes']);
    }
}
