<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Order\Models\Order;

class OrderConfirmationPage extends Component
{
    public string $orderNumber;

    public function mount(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function render(): View
    {
        $order = Order::query()->where('order_number', $this->orderNumber)->with('items')->first();

        return view('theme::pages.order-confirmation', [
            'order' => $order,
        ])->layout('theme::layouts.app', ['title' => 'Order Confirmation']);
    }
}
