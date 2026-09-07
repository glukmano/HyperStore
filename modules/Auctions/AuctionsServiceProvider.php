<?php

declare(strict_types=1);

namespace Modules\Auctions;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Livewire\Livewire;
use Modules\Auctions\Console\Commands\ProcessAuctionLifecycleCommand;
use Modules\Auctions\Contracts\AuctionEligibilityGateInterface;
use Modules\Auctions\Contracts\AuctionOrderSettlementHookInterface;
use Modules\Auctions\Livewire\ControlCenter\AuctionManager;
use Modules\Auctions\Livewire\Storefront\AuctionBidWidget;
use Modules\Auctions\Services\AuctionEligibilityGate;
use Modules\Auctions\Services\AuctionOrderSettlementHook;

class AuctionsServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(AuctionEligibilityGateInterface::class, AuctionEligibilityGate::class);
        $this->app->singleton(AuctionOrderSettlementHookInterface::class, AuctionOrderSettlementHook::class);
    }

    public function boot(): void
    {
        parent::boot();

        $viewsDir = __DIR__.'/Resources/views';
        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'auctions');
        }

        $webRoutesPath = __DIR__.'/Routes/web.php';
        if (file_exists($webRoutesPath)) {
            $this->loadRoutesFrom($webRoutesPath);
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();

        $this->commands([
            ProcessAuctionLifecycleCommand::class,
        ]);
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('auctions.control-center.auction-manager', AuctionManager::class);
        Livewire::component('auctions.storefront.auction-bid-widget', AuctionBidWidget::class);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('auctions.auctions', 'Auctions', 'control-center.auctions.auctions', 'Auctions', 'auctions.view', 'tenant', 'gavel', 10));
    }
}
