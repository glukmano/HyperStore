<?php

declare(strict_types=1);

namespace Modules\GiftCards;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\GiftCards\Listeners\IssueGiftCardOnPaymentCaptured;
use Modules\GiftCards\Livewire\ControlCenter\GiftCardManager;
use Modules\GiftCards\Livewire\Storefront\RedeemGiftCardWidget;
use Modules\Payment\Events\PaymentCaptured;

class GiftCardsServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
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
            $this->loadViewsFrom($viewsDir, 'gift-cards');
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();

        Event::listen(PaymentCaptured::class, IssueGiftCardOnPaymentCaptured::class);
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('gift-cards.control-center.gift-card-manager', GiftCardManager::class);
        Livewire::component('gift-cards.storefront.redeem-gift-card-widget', RedeemGiftCardWidget::class);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('gift-cards.manage', 'Gift Cards', 'control-center.gift-cards.manage', 'Wallet', 'gift-cards.view', 'tenant', '🎁', 20));
    }
}
