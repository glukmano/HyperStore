<?php

declare(strict_types=1);

namespace Modules\Order\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;

class OrderList extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('orders.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        $orders = Order::query()
            ->where('tenant_id', $tenantId)
            ->when($this->statusFilter !== '', fn ($query) => $query->where('order_status', $this->statusFilter))
            ->orderByDesc('placed_at')
            ->paginate(15);

        return view('order::livewire.order-list', [
            'orders' => $orders,
            'statuses' => array_map(fn (OrderStatus $status) => $status->value, OrderStatus::cases()),
        ])->layout('layouts.control-center', ['title' => 'Orders']);
    }
}
