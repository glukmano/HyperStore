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

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('catalog::livewire.attribute-set-manager', [
            'sets' => AttributeSet::where('tenant_id', $tenantId)->with(['groups', 'attributes'])->get(),
            'allAttributes' => Attribute::where('tenant_id', $tenantId)->where('status', 'active')->get(),
        ])->layout('layouts.control-center', ['title' => 'Attribute Sets']);
    }
}
