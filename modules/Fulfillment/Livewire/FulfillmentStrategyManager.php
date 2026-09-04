<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Fulfillment\Models\FulfillmentStrategy;

class FulfillmentStrategyManager extends Component
{
    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()?->getId();
        $strategies = $tenantId ? FulfillmentStrategy::where('tenant_id', $tenantId)->get() : collect();

        return view('fulfillment::livewire.strategy-manager', ['strategies' => $strategies])
            ->layout('layouts.control-center', ['title' => 'Fulfillment Strategies']);
    }
}
