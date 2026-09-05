<?php

declare(strict_types=1);

namespace Modules\Search\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Models\Product;
use Modules\Search\Models\SearchMerchandisingRule;

class MerchandisingManager extends Component
{
    public string $queryTerm = '';

    public string $sku = '';

    public int $pinPosition = 1;

    public ?string $error = null;

    public function create(): void
    {
        $this->authorizeManage();
        $this->error = null;

        $this->validate([
            'queryTerm' => 'required|string|max:255',
            'sku' => 'required|string',
            'pinPosition' => 'required|integer|min:1',
        ]);

        $tenantId = $this->tenantId();
        $product = Product::query()->where('tenant_id', $tenantId)->where('sku', $this->sku)->first();

        if ($product === null) {
            $this->error = 'Product not found for that SKU.';

            return;
        }

        SearchMerchandisingRule::query()->create([
            'tenant_id' => $tenantId,
            'query_term' => mb_strtolower(trim($this->queryTerm)),
            'product_id' => $product->id,
            'pin_position' => $this->pinPosition,
            'is_active' => true,
        ]);

        $this->reset(['queryTerm', 'sku']);
        $this->pinPosition = 1;
        session()->flash('success', 'Merchandising rule created.');
    }

    public function toggleActive(int $ruleId): void
    {
        $this->authorizeManage();
        $rule = SearchMerchandisingRule::query()->where('tenant_id', $this->tenantId())->findOrFail($ruleId);
        $rule->is_active = ! $rule->is_active;
        $rule->save();
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        $rules = SearchMerchandisingRule::query()->where('tenant_id', $this->tenantId())->with('product')->latest()->paginate(20);

        return view('livewire.control-center.search.merchandising-manager', ['rules' => $rules])
            ->layout('layouts.control-center', ['title' => 'Search Merchandising']);
    }

    private function tenantId(): int
    {
        return (int) app(ContextManager::class)->getTenant()->getId();
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('search.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
