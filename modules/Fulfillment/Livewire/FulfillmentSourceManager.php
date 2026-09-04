<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Fulfillment\Models\FulfillmentSourceConfiguration;

class FulfillmentSourceManager extends Component
{
    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()?->getId();
        $configs = $tenantId ? FulfillmentSourceConfiguration::where('tenant_id', $tenantId)->get() : collect();

        return view('fulfillment::livewire.source-manager', ['configs' => $configs])
            ->layout('layouts.control-center', ['title' => 'Fulfillment Sources']);
    }
}
