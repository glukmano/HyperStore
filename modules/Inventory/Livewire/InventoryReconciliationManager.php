<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Inventory\Services\InventoryReconciliationService;

class InventoryReconciliationManager extends Component
{
    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function runReconciliation(InventoryReconciliationService $service): void
    {
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;
        $this->report = $service->reconcile($tenantId);
    }

    public function render(): View|Factory
    {
        return view('inventory::livewire.inventory-reconciliation-manager')
            ->layout('layouts.control-center', ['title' => 'Inventory Reconciliation']);
    }
}
