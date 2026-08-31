<?php

declare(strict_types=1);

namespace Modules\Pricing\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Pricing\Models\PriceBook;

class PriceBookManager extends Component
{
    public string $name = '';

    public string $code = '';

    public string $currency = 'USD';

    public int $priority = 0;

    public bool $is_default = false;

    public function createPriceBook(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        PriceBook::create([
            'tenant_id' => $tenantId,
            'name' => $this->name,
            'code' => $this->code,
            'currency' => strtoupper($this->currency),
            'priority' => $this->priority,
            'is_default' => $this->is_default,
            'status' => 'active',
        ]);

        $this->reset(['name', 'code', 'currency', 'priority', 'is_default']);
        session()->flash('success', 'Price Book created.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('pricing::livewire.price-book-manager', [
            'priceBooks' => PriceBook::where('tenant_id', $tenantId)->orderByDesc('priority')->get(),
        ])->layout('layouts.control-center', ['title' => 'Price Books']);
    }
}
