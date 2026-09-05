<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\ReferenceData\Models\Country;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Owner Delta §9: activate/deactivate only, ISO-3166-1 alpha-2/alpha-3
 * codes as the authoritative key (never a translated name).
 */
class CountryManager extends Component
{
    public string $iso2 = '';

    public string $iso3 = '';

    public string $name = '';

    public string $native_name = '';

    public bool $is_active = true;

    public function createCountry(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'iso2' => ['required', 'string', 'size:2'],
            'iso3' => ['required', 'string', 'size:3'],
            'name' => ['required', 'string', 'max:100'],
            'native_name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        Country::create([
            'iso2' => strtoupper($validated['iso2']),
            'iso3' => strtoupper($validated['iso3']),
            'name' => $validated['name'],
            'native_name' => $validated['native_name'],
            'is_active' => (bool) $validated['is_active'],
        ]);

        $this->reset(['iso2', 'iso3', 'name', 'native_name']);
        session()->flash('success', 'Country created successfully.');
    }

    public function deactivateCountry(int $countryId): void
    {
        $this->authorizeManage();

        Country::where('id', $countryId)->update(['is_active' => false]);
        session()->flash('success', 'Country deactivated.');
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('countries.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    public function render(): View
    {
        return view('livewire.control-center.country-manager', [
            'countries' => Country::query()->orderBy('name')->get(),
        ])->layout('layouts.control-center', ['title' => 'Countries']);
    }
}
