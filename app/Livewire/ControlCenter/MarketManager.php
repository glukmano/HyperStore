<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Markets\Models\Market;
use App\Core\Markets\Services\MarketDefaultsService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;

class MarketManager extends Component
{
    public string $name = '';

    public string $code = '';

    public string $default_currency_code = '';

    public string $default_locale_code = 'en';

    public string $timezone = 'UTC';

    public bool $is_active = true;

    public function createMarket(): void
    {
        if (! auth()->user()?->can('markets.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'default_currency_code' => ['required', 'string', 'size:3'],
            'default_locale_code' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $tenantId = app(ContextManager::class)->getTenant()->getId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context required.');
        }

        $market = Market::create([
            'tenant_id' => (int) $tenantId,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'default_currency_code' => strtoupper((string) $validated['default_currency_code']),
            'default_locale_code' => $validated['default_locale_code'],
            'timezone' => $validated['timezone'],
            'is_active' => (bool) $validated['is_active'],
        ]);

        // Owner Delta §8: the chosen default Locale/Currency must be an
        // actual member of this Market from the moment it exists.
        app(MarketDefaultsService::class)->bootstrapDefaults($market);

        $this->reset(['name', 'code']);
        session()->flash('success', 'Market created successfully.');
    }

    /**
     * Owner Delta §9: reference data is deactivated, never hard-deleted —
     * there is intentionally no destructive delete action on this screen.
     */
    public function deactivateMarket(int $marketId): void
    {
        if (! auth()->user()?->can('markets.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = app(ContextManager::class)->getTenant()->getId();
        $market = Market::where('id', $marketId)->where('tenant_id', $tenantId)->first();
        $market?->update(['is_active' => false]);

        session()->flash('success', 'Market deactivated.');
    }

    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();

        $markets = $tenantId !== null
            ? Market::where('tenant_id', (int) $tenantId)->orderByDesc('id')->get()
            : collect();

        return view('livewire.control-center.market-manager', [
            'markets' => $markets,
        ])->layout('layouts.control-center', ['title' => 'Markets']);
    }
}
