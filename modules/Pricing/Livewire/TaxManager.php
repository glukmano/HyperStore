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
