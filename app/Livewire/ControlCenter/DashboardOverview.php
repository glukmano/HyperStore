<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Localization\Contracts\LocaleManagerInterface;
use App\Core\Modular\Contracts\ModuleKernelInterface;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard — Control Center')]
class DashboardOverview extends Component
{
    public string $locale;

    public string $direction;

    public bool $tenantResolved;

    public bool $storeResolved;

    public bool $vendorResolved;

    public int $enabledModules;

    public int $disabledModules;

    public function mount(
        LocaleManagerInterface $localeManager,
        ContextManager $contextManager,
        ModuleKernelInterface $kernel,
    ): void {
        $this->locale = $localeManager->getLocale();
        $this->direction = $localeManager->isRtl() ? 'rtl' : 'ltr';
        $this->tenantResolved = $contextManager->hasTenant();
        $this->storeResolved = $contextManager->hasStore();
        $this->vendorResolved = $contextManager->hasVendor();
        $this->enabledModules = count($kernel->getRegistry()->enabled());
        $this->disabledModules = count($kernel->getRegistry()->disabled());
    }

    public function render(NavigationRegistryInterface $navigation): View
    {
        $navContext = app(ContextManager::class)->hasVendor() ? 'vendor' : 'tenant';

        return view('livewire.control-center.dashboard-overview', [
            'navGroups' => $navigation->visibleGrouped(auth()->user(), $navContext),
        ])->layout('layouts.control-center', ['title' => 'Dashboard']);
    }
}
