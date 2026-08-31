<?php

declare(strict_types=1);

namespace Modules\Pricing\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Pricing\Models\ExchangeRate;

class ExchangeRateManager extends Component
{
    public string $baseCurrency = 'USD';

    public string $targetCurrency = 'EUR';

    public string $rate = '0.92000000';

    public function setRate(): void
    {
        $this->validate([
            'baseCurrency' => ['required', 'string', 'size:3'],
            'targetCurrency' => ['required', 'string', 'size:3'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        ExchangeRate::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'base_currency' => strtoupper($this->baseCurrency),
                'target_currency' => strtoupper($this->targetCurrency),
            ],
            [
                'rate' => $this->rate,
                'source' => 'manual',
                'is_stale' => false,
                'effective_at' => now(),
            ]
        );

        session()->flash('success', 'Exchange rate updated.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('pricing::livewire.exchange-rate-manager', [
            'rates' => ExchangeRate::where('tenant_id', $tenantId)->get(),
        ])->layout('layouts.control-center', ['title' => 'Exchange Rates']);
    }
}
