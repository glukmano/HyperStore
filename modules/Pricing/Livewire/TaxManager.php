<?php

declare(strict_types=1);

namespace Modules\Pricing\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Pricing\Models\TaxClass;
use Modules\Pricing\Models\TaxRate;
use Modules\Pricing\Models\TaxZone;

class TaxManager extends Component
{
    public string $className = '';

    public string $classCode = '';

    public string $zoneName = '';

    public string $zoneCode = '';

    public string $countryCode = 'US';

    public ?int $editingClassId = null;

    public string $editClassName = '';

    public string $editClassCode = '';

    public ?int $editingZoneId = null;

    public string $editZoneName = '';

    public string $editZoneCode = '';

    public string $editCountryCode = 'US';

    public function createClass(): void
    {
        $this->validate(['className' => 'required', 'classCode' => 'required']);
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        TaxClass::create([
            'tenant_id' => $tenantId,
            'name' => $this->className,
            'code' => $this->classCode,
        ]);
        $this->reset(['className', 'classCode']);
    }

    public function editClass(int $id): void
    {
        if (! auth()->user()?->can('tax.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $class = TaxClass::where('tenant_id', $tenantId)->findOrFail($id);

        $this->editingClassId = $class->id;
        $this->editClassName = $class->name;
        $this->editClassCode = $class->code;
    }

    public function cancelEditClass(): void
    {
        $this->reset(['editingClassId', 'editClassName', 'editClassCode']);
    }

    public function updateClass(): void
    {
        if (! auth()->user()?->can('tax.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->editingClassId === null) {
            return;
        }

        $this->validate(['editClassName' => 'required', 'editClassCode' => 'required']);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $class = TaxClass::where('tenant_id', $tenantId)->findOrFail($this->editingClassId);

        $class->update([
            'name' => $this->editClassName,
            'code' => $this->editClassCode,
        ]);

        $this->reset(['editingClassId', 'editClassName', 'editClassCode']);
        session()->flash('success', 'Tax class updated.');
    }

    public function createZone(): void
    {
        $this->validate(['zoneName' => 'required', 'zoneCode' => 'required', 'countryCode' => 'required']);
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        TaxZone::create([
            'tenant_id' => $tenantId,
            'name' => $this->zoneName,
            'code' => $this->zoneCode,
            'country_code' => strtoupper($this->countryCode),
        ]);
        $this->reset(['zoneName', 'zoneCode', 'countryCode']);
    }

    public function editZone(int $id): void
    {
        if (! auth()->user()?->can('tax.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $zone = TaxZone::where('tenant_id', $tenantId)->findOrFail($id);

        $this->editingZoneId = $zone->id;
        $this->editZoneName = $zone->name;
        $this->editZoneCode = $zone->code;
        $this->editCountryCode = $zone->country_code ?? 'US';
    }

    public function cancelEditZone(): void
    {
        $this->reset(['editingZoneId', 'editZoneName', 'editZoneCode', 'editCountryCode']);
    }

    public function updateZone(): void
    {
        if (! auth()->user()?->can('tax.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->editingZoneId === null) {
            return;
        }

        $this->validate(['editZoneName' => 'required', 'editZoneCode' => 'required', 'editCountryCode' => 'required']);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $zone = TaxZone::where('tenant_id', $tenantId)->findOrFail($this->editingZoneId);

        $zone->update([
            'name' => $this->editZoneName,
            'code' => $this->editZoneCode,
            'country_code' => strtoupper($this->editCountryCode),
        ]);

        $this->reset(['editingZoneId', 'editZoneName', 'editZoneCode', 'editCountryCode']);
        session()->flash('success', 'Tax zone updated.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('pricing::livewire.tax-manager', [
            'classes' => TaxClass::where('tenant_id', $tenantId)->get(),
            'zones' => TaxZone::where('tenant_id', $tenantId)->get(),
            'rates' => TaxRate::where('tenant_id', $tenantId)->with(['taxClass', 'taxZone'])->get(),
        ])->layout('layouts.control-center', ['title' => 'Tax Management']);
    }
}
