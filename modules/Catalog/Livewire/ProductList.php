<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Catalog\Actions\ArchiveProductAction;
use Modules\Catalog\Models\Product;

class ProductList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $selectedType = '';

    public string $selectedStatus = '';

    public ?int $confirmArchiveId = null;

    public function openArchiveConfirm(int $id): void
    {
        $this->confirmArchiveId = $id;
    }

    public function cancelArchiveConfirm(): void
    {
        $this->confirmArchiveId = null;
    }

    public function archiveProduct(): void
    {
        if (! auth()->user()?->can('products.archive') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->confirmArchiveId === null) {
            return;
        }

        $contextManager = app(ContextManager::class);
        $tenantId = $contextManager->getTenant()->getId();

        $product = Product::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->findOrFail($this->confirmArchiveId);

        app(ArchiveProductAction::class)->execute($product);

        $this->confirmArchiveId = null;
        session()->flash('success', 'Product archived successfully.');
    }

    public function render(): View|Factory
    {
        $contextManager = app(ContextManager::class);
        $tenantId = $contextManager->getTenant()->getId();

        $query = Product::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', '!=', 'archived')
            ->with(['translations', 'brand.translations', 'categories.translations'])
            ->latest();

        if ($this->selectedType !== '') {
            $query->where('product_type', $this->selectedType);
        }

        if ($this->selectedStatus !== '') {
            $query->where('status', $this->selectedStatus);
        }

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('sku', 'like', "%{$this->search}%")
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$this->search}%"));
            });
        }

        return view('catalog::livewire.product-list', [
            'products' => $query->paginate(15),
        ])->layout('layouts.control-center', ['title' => 'Catalog Products']);
    }
}
