<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Shipping\Models\ShippingRateRule;

class RateRuleManager extends Component
{
    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        $rules = $tenantId
            ? ShippingRateRule::query()->whereHas('method', fn ($q) => $q->where('tenant_id', $tenantId))->get()
            : collect();

        return view('shipping::livewire.rate-rule-manager', ['rules' => $rules])
            ->layout('layouts.control-center', ['title' => 'Rate Rules']);
    }
}
