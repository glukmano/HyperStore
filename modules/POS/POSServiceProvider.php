<?php

declare(strict_types=1);

namespace Modules\POS;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Livewire\Livewire;
use Modules\POS\Contracts\PosCashRefundServiceInterface;
use Modules\POS\Contracts\PosOrderContextHookInterface;
use Modules\POS\Livewire\ControlCenter\CashMovementLedgerViewer;
use Modules\POS\Livewire\ControlCenter\ManualDiscountAuditViewer;
use Modules\POS\Livewire\ControlCenter\RegisterManager;
use Modules\POS\Livewire\ControlCenter\RegisterSessionMonitor;
use Modules\POS\Livewire\Terminal\PosRegisterOpenClose;
use Modules\POS\Livewire\Terminal\PosTerminal;
use Modules\POS\Services\PosCashRefundService;
use Modules\POS\Services\PosOrderContextHook;

class POSServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(PosOrderContextHookInterface::class, PosOrderContextHook::class);
        $this->app->singleton(PosCashRefundServiceInterface::class, PosCashRefundService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $webRoutesPath = __DIR__.'/Routes/web.php';
        if (file_exists($webRoutesPath)) {
            $this->loadRoutesFrom($webRoutesPath);
        }

        $viewsDir = __DIR__.'/Resources/views';
        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'pos');
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('pos.control-center.register-manager', RegisterManager::class);
        Livewire::component('pos.control-center.register-session-monitor', RegisterSessionMonitor::class);
        Livewire::component('pos.control-center.cash-movement-ledger-viewer', CashMovementLedgerViewer::class);
        Livewire::component('pos.control-center.manual-discount-audit-viewer', ManualDiscountAuditViewer::class);
        Livewire::component('pos.terminal.register-open-close', PosRegisterOpenClose::class);
        Livewire::component('pos.terminal.pos-terminal', PosTerminal::class);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('pos.registers', 'POS Registers', 'control-center.pos.registers', 'POS', 'pos.registers.view', 'tenant', '🧾', 10));
        $nav->register(new NavigationItem('pos.sessions', 'Register Sessions', 'control-center.pos.sessions', 'POS', 'pos.registers.view', 'tenant', '🧾', 20));
        $nav->register(new NavigationItem('pos.cash-movements', 'Cash Movements', 'control-center.pos.cash-movements', 'POS', 'pos.registers.view', 'tenant', '🧾', 30));
        $nav->register(new NavigationItem('pos.manual-discounts', 'Manual Discount Audit', 'control-center.pos.manual-discounts', 'POS', 'pos.registers.view', 'tenant', '🧾', 40));
    }
}
