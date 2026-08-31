<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Shipping\Models\ShippingMethod;
use RuntimeException;

class ShippingMethodManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $rateCalculatorType = 'flat_rate';

    public string $currency = 'CHF';

    public int $baseAmount = 0;

    public int $handlingFee = 0;

    public int $priority = 0;

    public function createMethod(): void
    {
        $tenant = app(ContextManager::class)->getTenant();
        if ($tenant === null) {
            throw new RuntimeException('Tenant context required.');
        }

        if (! auth()->user()?->can('shipping.methods.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'rateCalculatorType' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'baseAmount' => ['required', 'integer'],
        ]);

        ShippingMethod::create([
            'tenant_id' => $tenant->getId(),
            'code' => $this->code,
            'name' => $this->name,
            'rate_calculator_type' => $this->rateCalculatorType,
            'currency' => $this->currency,
            'base_amount' => $this->baseAmount,
            'handling_fee' => $this->handlingFee,
            'priority' => $this->priority,
            'status' => 'active',
        ]);

        $this->reset(['code', 'name', 'baseAmount', 'handlingFee', 'priority']);
    }

    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()?->getId();
        $methods = $tenantId ? ShippingMethod::where('tenant_id', $tenantId)->with('methodZones')->get() : collect();

        return view('shipping::livewire.shipping-method-manager', ['methods' => $methods]);
    }
}
