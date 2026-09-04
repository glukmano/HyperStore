<?php

declare(strict_types=1);

namespace Modules\Order\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Order\Models\Order;

class OrderDetail extends Component
{
    public int $orderId;

    public function mount(int $orderId): void
    {
        if (! auth()->user()?->can('orders.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->orderId = $orderId;
    }

    public function render(): View|Factory
    {
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'items',
                'sellerOrders.vendor',
                'sellerOrders.fulfillments',
                'returnRequests.sellerReturns.items',
            ])
            ->findOrFail($this->orderId);

        return view('order::livewire.order-detail', [
            'order' => $order,
        ])->layout('layouts.control-center', ['title' => 'Order '.$order->order_number]);
    }
}
