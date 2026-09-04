<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Order\Models\Order;

class OrderLookupPage extends Component
{
    public string $orderNumber = '';

    public string $email = '';

    public bool $searched = false;

    public ?Order $foundOrder = null;

    public function lookup(): void
    {
        $this->validate([
            'orderNumber' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $this->searched = true;

        $order = Order::query()
            ->where('order_number', trim($this->orderNumber))
            ->with('items')
            ->first();

        if ($order !== null && strtolower((string) ($order->customer_snapshot['email'] ?? '')) === strtolower(trim($this->email))) {
            $this->foundOrder = $order;
        } else {
            $this->foundOrder = null;
        }
    }

    public function render(): View
    {
        return view('theme::pages.order-lookup')
            ->layout('theme::layouts.app', ['title' => 'Track Order']);
    }
}
