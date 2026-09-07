<?php

declare(strict_types=1);

namespace Modules\POS\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\POS\Models\PosRegisterSession;
use RuntimeException;

class RegisterSessionMonitor extends Component
{
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
        $sessions = PosRegisterSession::where('tenant_id', $this->tenantId())
            ->with(['register', 'cashier'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('pos::livewire.control-center.register-session-monitor', [
            'sessions' => $sessions,
        ])->layout('layouts.control-center', ['title' => 'Register Sessions']);
    }
}
