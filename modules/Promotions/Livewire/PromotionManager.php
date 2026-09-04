<?php

declare(strict_types=1);

namespace Modules\Promotions\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Promotions\Models\Promotion;

class PromotionManager extends Component
{
    public string $name = '';

    public string $code = '';

    public int $priority = 0;

    public bool $is_exclusive = false;

    public ?int $editingId = null;

    public string $editName = '';

    public string $editCode = '';

    public int $editPriority = 0;

    public bool $editIsExclusive = false;

    public function createPromotion(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        Promotion::create([
            'tenant_id' => $tenantId,
            'name' => $this->name,
            'code' => $this->code,
            'priority' => $this->priority,
            'is_exclusive' => $this->is_exclusive,
            'status' => 'active',
        ]);

        $this->reset(['name', 'code', 'priority', 'is_exclusive']);
        session()->flash('success', 'Promotion created.');
    }

    public function editPromotion(int $id): void
    {
        if (! auth()->user()?->can('promotions.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $promotion = Promotion::where('tenant_id', $tenantId)->findOrFail($id);

        $this->editingId = $promotion->id;
        $this->editName = $promotion->name;
        $this->editCode = $promotion->code;
        $this->editPriority = $promotion->priority;
        $this->editIsExclusive = (bool) $promotion->is_exclusive;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editName', 'editCode', 'editPriority', 'editIsExclusive']);
    }

    public function updatePromotion(): void
    {
        if (! auth()->user()?->can('promotions.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->editingId === null) {
            return;
        }

        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editCode' => ['required', 'string', 'max:100'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $promotion = Promotion::where('tenant_id', $tenantId)->findOrFail($this->editingId);

        $promotion->update([
            'name' => $this->editName,
            'code' => $this->editCode,
            'priority' => $this->editPriority,
            'is_exclusive' => $this->editIsExclusive,
        ]);

        $this->reset(['editingId', 'editName', 'editCode', 'editPriority', 'editIsExclusive']);
        session()->flash('success', 'Promotion updated.');
    }

    /**
     * Deactivate/reactivate via the existing `status` lifecycle field — the same
     * mechanism PromotionRuleEngine already reads (`where('status', 'active')`).
     * No hard delete exists or is invented; this is the real existing lifecycle op.
     */
    public function toggleStatus(int $id): void
    {
        if (! auth()->user()?->can('promotions.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $promotion = Promotion::where('tenant_id', $tenantId)->findOrFail($id);

        $promotion->update(['status' => $promotion->status === 'active' ? 'inactive' : 'active']);

        session()->flash('success', $promotion->status === 'active' ? 'Promotion activated.' : 'Promotion deactivated.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('promotions::livewire.promotion-manager', [
            'promotions' => Promotion::where('tenant_id', $tenantId)->orderByDesc('priority')->get(),
        ])->layout('layouts.control-center', ['title' => 'Promotions']);
    }
}
