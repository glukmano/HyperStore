<?php

declare(strict_types=1);

namespace Modules\POS\Livewire\Terminal;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\POS\Enums\RegisterSessionStatus;
use Modules\POS\Models\PosRegister;
use Modules\POS\Models\PosRegisterSession;
use Modules\POS\Services\PosRegisterSessionService;
use RuntimeException;
use Throwable;

class PosRegisterOpenClose extends Component
{
    public int $registerId = 0;

    public string $openingFloat = '0.00';

    public string $closingCounted = '0.00';

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->assertCan('pos.register.open');
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

    private function activeSession(): ?PosRegisterSession
    {
        return PosRegisterSession::where('tenant_id', $this->tenantId())
            ->where('cashier_user_id', auth()->id())
            ->where('status', RegisterSessionStatus::ACTIVE->value)
            ->first();
    }

    public function open(PosRegisterSessionService $service): void
    {
        $this->assertCan('pos.register.open');
        $this->errorMessage = null;

        $this->validate([
            'registerId' => 'required|integer|min:1',
            'openingFloat' => 'required|numeric|min:0',
        ]);

        try {
            /** @var PosRegister $register */
            $register = PosRegister::where('tenant_id', $this->tenantId())->findOrFail($this->registerId);

            $session = $service->open(
                $register,
                (int) auth()->id(),
                'USD',
                (int) round(((float) $this->openingFloat) * 100)
            );

            $this->redirect(route('pos.terminal.sale', ['session' => $session->id]));
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function close(PosRegisterSessionService $service): void
    {
        $this->assertCan('pos.session.close');
        $this->errorMessage = null;

        $session = $this->activeSession();
        if ($session === null) {
            $this->errorMessage = 'No active session to close.';

            return;
        }

        try {
            $service->close($session, (int) round(((float) $this->closingCounted) * 100), (int) auth()->id());
            session()->flash('success', 'Register session closed.');
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(): View
    {
        $activeSession = $this->activeSession();

        $registers = PosRegister::where('tenant_id', $this->tenantId())
            ->where('status', 'active')
            ->with(['storeMarket.store'])
            ->get();

        return view('pos::livewire.terminal.register-open-close', [
            'activeSession' => $activeSession,
            'registers' => $registers,
        ])->layout('layouts.control-center', ['title' => 'POS Register']);
    }
}
