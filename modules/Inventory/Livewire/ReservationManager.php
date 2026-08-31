<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Inventory\Models\InventoryReservation;

class ReservationManager extends Component
{
    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('inventory::livewire.reservation-manager', [
            'reservations' => InventoryReservation::where('tenant_id', $tenantId)->with('allocations')->latest()->paginate(25),
        ])->layout('layouts.control-center', ['title' => 'Stock Reservations']);
    }
}
