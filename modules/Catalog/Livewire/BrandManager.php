<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\BrandTranslation;

class BrandManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $slug = '';

    public function createBrand(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
        ]);

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        /** @var Brand $brand */
        $brand = Brand::create([
            'tenant_id' => (int) $tenantId,
            'code' => $this->code,
            'status' => 'active',
        ]);

        BrandTranslation::create([
            'brand_id' => $brand->id,
            'locale' => app()->getLocale(),
            'name' => $this->name,
            'slug' => $this->slug,
        ]);

        $this->reset(['code', 'name', 'slug']);
        session()->flash('success', 'Brand created.');
    }

    public function render(): View|Factory
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        return view('catalog::livewire.brand-manager', [
            'brands' => Brand::where('tenant_id', $tenantId)->with('translations')->get(),
        ])->layout('layouts.control-center', ['title' => 'Catalog Brands']);
    }
}
