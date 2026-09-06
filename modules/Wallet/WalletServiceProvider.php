<?php

declare(strict_types=1);

namespace Modules\Wallet;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Wallet\Contracts\StoreValueCheckoutHookInterface;
use Modules\Wallet\Contracts\StoreValueServiceInterface;
use Modules\Wallet\Listeners\ConvertStoreValueHoldOnPaymentCaptured;
use Modules\Wallet\Livewire\ControlCenter\StoreValueAccountManager;
use Modules\Wallet\Livewire\Storefront\StoreValueApplyWidget;
use Modules\Wallet\Livewire\Storefront\WalletPage;
use Modules\Wallet\Services\StoreValueCheckoutHook;
use Modules\Wallet\Services\StoreValueService;

class WalletServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(StoreValueServiceInterface::class, StoreValueService::class);
        $this->app->singleton(StoreValueCheckoutHookInterface::class, StoreValueCheckoutHook::class);
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
            $this->loadViewsFrom($viewsDir, 'wallet');
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();

        Event::listen(PaymentCaptured::class, ConvertStoreValueHoldOnPaymentCaptured::class);
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('wallet.control-center.store-value-account-manager', StoreValueAccountManager::class);
        Livewire::component('wallet.storefront.wallet-page', WalletPage::class);
        Livewire::component('wallet.storefront.store-value-apply-widget', StoreValueApplyWidget::class);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('wallet.accounts', 'Store Value', 'control-center.wallet.accounts', 'Wallet', 'wallet.accounts.view', 'tenant', '💰', 10));
    }
}
