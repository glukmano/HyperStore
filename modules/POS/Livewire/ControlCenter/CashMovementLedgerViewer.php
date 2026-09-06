<?php

declare(strict_types=1);

namespace Modules\POS\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\POS\Models\PosCashMovement;
use RuntimeException;

class CashMovementLedgerViewer extends Component
{
    public ?int $registerSessionId = null;

    public function mount(): void
    {
        $this->assertCan('pos.registers.view');
    }

    private function assertCan(string $permission): void
    {
        if (! auth()->user()?->can($permission) && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    private function tenantId(): int
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context required.');
        }

        return (int) $tenantId;
    }

    public function render(): View
    {
        $query = PosCashMovement::where('tenant_id', $this->tenantId())->with('createdBy');

        if ($this->registerSessionId !== null) {
            $query->where('register_session_id', $this->registerSessionId);
        }

        $movements = $query->orderByDesc('id')->limit(200)->get();

        return view('pos::livewire.control-center.cash-movement-ledger-viewer', [
            'movements' => $movements,
        ]);
    }
}
