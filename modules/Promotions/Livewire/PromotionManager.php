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

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('promotions::livewire.promotion-manager', [
            'promotions' => Promotion::where('tenant_id', $tenantId)->orderByDesc('priority')->get(),
        ])->layout('layouts.control-center', ['title' => 'Promotions']);
    }
}
