<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\ReferenceData\Models\Currency;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Owner Delta §9: activate/deactivate only. No second currency engine —
 * this is purely reference-data CRUD; PriceResolver/MoneyValue/
 * CurrencyConversionService are untouched.
 */
class CurrencyManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $symbol = '';

    public int $decimals = 2;

    public bool $is_active = true;

    public function createCurrency(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'code' => ['required', 'string', 'size:3'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:10'],
            'decimals' => ['required', 'integer', 'min:0', 'max:6'],
            'is_active' => ['boolean'],
        ]);

        Currency::create([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'symbol' => $validated['symbol'],
            'decimals' => $validated['decimals'],
            'is_active' => (bool) $validated['is_active'],
            'is_default' => false,
        ]);

        $this->reset(['code', 'name', 'symbol']);
        session()->flash('success', 'Currency created successfully.');
    }

    public function deactivateCurrency(int $currencyId): void
    {
        $this->authorizeManage();

        $currency = Currency::find($currencyId);
        if ($currency === null) {
            return;
        }

        if ($currency->is_default) {
            session()->flash('error', 'Cannot deactivate the platform default Currency.');

            return;
        }

        $currency->update(['is_active' => false]);
        session()->flash('success', 'Currency deactivated.');
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('currencies.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    public function render(): View
    {
        return view('livewire.control-center.currency-manager', [
            'currencies' => Currency::query()->orderBy('code')->get(),
        ])->layout('layouts.control-center', ['title' => 'Currencies']);
    }
}
