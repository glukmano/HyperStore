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

    public ?int $editingId = null;

    public string $editName = '';

    public string $editCode = '';

    public string $editCurrency = 'USD';

    public int $editPriority = 0;

    public bool $editIsDefault = false;

    public ?int $confirmArchiveId = null;

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

    public function editPriceBook(int $id): void
    {
        if (! auth()->user()?->can('pricing.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        $priceBook = PriceBook::where('tenant_id', $tenantId)->findOrFail($id);

        $this->editingId = $priceBook->id;
        $this->editName = $priceBook->name;
        $this->editCode = $priceBook->code;
        $this->editCurrency = $priceBook->currency;
        $this->editPriority = $priceBook->priority;
        $this->editIsDefault = $priceBook->is_default;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editName', 'editCode', 'editCurrency', 'editPriority', 'editIsDefault']);
    }

    public function updatePriceBook(): void
    {
        if (! auth()->user()?->can('pricing.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->editingId === null) {
            return;
        }

        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editCode' => ['required', 'string', 'max:100'],
            'editCurrency' => ['required', 'string', 'size:3'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        $priceBook = PriceBook::where('tenant_id', $tenantId)->findOrFail($this->editingId);

        $priceBook->update([
            'name' => $this->editName,
            'code' => $this->editCode,
            'currency' => strtoupper($this->editCurrency),
            'priority' => $this->editPriority,
            'is_default' => $this->editIsDefault,
        ]);

        $this->reset(['editingId', 'editName', 'editCode', 'editCurrency', 'editPriority', 'editIsDefault']);
        session()->flash('success', 'Price Book updated.');
    }

    public function openArchiveConfirm(int $id): void
    {
        $this->confirmArchiveId = $id;
    }

    public function cancelArchiveConfirm(): void
    {
        $this->confirmArchiveId = null;
    }

    public function archivePriceBook(): void
    {
        if (! auth()->user()?->can('pricing.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->confirmArchiveId === null) {
            return;
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        $priceBook = PriceBook::where('tenant_id', $tenantId)->findOrFail($this->confirmArchiveId);
        $priceBook->update(['status' => $priceBook->status === 'archived' ? 'active' : 'archived']);

        $this->confirmArchiveId = null;
        session()->flash('success', $priceBook->status === 'archived' ? 'Price Book archived.' : 'Price Book reactivated.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('pricing::livewire.price-book-manager', [
            'priceBooks' => PriceBook::where('tenant_id', $tenantId)->orderByDesc('priority')->get(),
        ])->layout('layouts.control-center', ['title' => 'Price Books']);
    }
}
