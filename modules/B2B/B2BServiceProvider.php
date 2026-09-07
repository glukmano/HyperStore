<?php

declare(strict_types=1);

namespace Modules\B2B;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Livewire\Livewire;
use Modules\B2B\Contracts\CompanyCreditServiceInterface;
use Modules\B2B\Contracts\CompanyOrderCreditHookInterface;
use Modules\B2B\Contracts\CustomerGroupResolverInterface;
use Modules\B2B\Contracts\QuoteLinePriceResolverInterface;
use Modules\B2B\Livewire\ControlCenter\CompanyCreditManager;
use Modules\B2B\Livewire\ControlCenter\CompanyManager;
use Modules\B2B\Livewire\ControlCenter\QuoteManager;
use Modules\B2B\Livewire\Storefront\CompanyAccountPage;
use Modules\B2B\Livewire\Storefront\QuoteRequestPage;
use Modules\B2B\Services\CompanyCreditService;
use Modules\B2B\Services\CompanyOrderCreditHook;
use Modules\B2B\Services\CustomerGroupResolver;
use Modules\B2B\Services\QuoteLinePriceResolver;

class B2BServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(CompanyCreditServiceInterface::class, CompanyCreditService::class);
        $this->app->singleton(CompanyOrderCreditHookInterface::class, CompanyOrderCreditHook::class);
        $this->app->singleton(CustomerGroupResolverInterface::class, CustomerGroupResolver::class);
        $this->app->singleton(QuoteLinePriceResolverInterface::class, QuoteLinePriceResolver::class);
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
            $this->loadViewsFrom($viewsDir, 'b2b');
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('b2b.control-center.company-manager', CompanyManager::class);
        Livewire::component('b2b.control-center.quote-manager', QuoteManager::class);
        Livewire::component('b2b.control-center.company-credit-manager', CompanyCreditManager::class);
        Livewire::component('b2b.storefront.company-account-page', CompanyAccountPage::class);
        Livewire::component('b2b.storefront.quote-request-page', QuoteRequestPage::class);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('b2b.companies', 'Companies', 'control-center.b2b.companies', 'B2B', 'b2b.companies.view', 'tenant', 'building-2', 10));
        $nav->register(new NavigationItem('b2b.quotes', 'Quotes', 'control-center.b2b.quotes', 'B2B', 'b2b.quotes.view', 'tenant', 'file', 20));
        $nav->register(new NavigationItem('b2b.credit', 'Company Credit', 'control-center.b2b.credit', 'B2B', 'b2b.companies.manage', 'tenant', 'credit-card', 30));
    }
}
