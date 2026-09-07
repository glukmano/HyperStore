<?php

declare(strict_types=1);

namespace Modules\Booking;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Livewire\Livewire;
use Modules\Booking\Console\Commands\ExpireBookingHoldsCommand;
use Modules\Booking\Console\Commands\GenerateBookingSlotsCommand;
use Modules\Booking\Contracts\BookingHoldHookInterface;
use Modules\Booking\Contracts\BookingOrderConfirmationHookInterface;
use Modules\Booking\Livewire\ControlCenter\BookingResourceManager;
use Modules\Booking\Livewire\Storefront\BookingWidget;
use Modules\Booking\Services\BookingHoldHook;
use Modules\Booking\Services\BookingOrderConfirmationHook;

class BookingServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(BookingHoldHookInterface::class, BookingHoldHook::class);
        $this->app->singleton(BookingOrderConfirmationHookInterface::class, BookingOrderConfirmationHook::class);
    }

    public function boot(): void
    {
        parent::boot();

        $viewsDir = __DIR__.'/Resources/views';
        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'booking');
        }

        $webRoutesPath = __DIR__.'/Routes/web.php';
        if (file_exists($webRoutesPath)) {
            $this->loadRoutesFrom($webRoutesPath);
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();

        $this->commands([
            ExpireBookingHoldsCommand::class,
            GenerateBookingSlotsCommand::class,
        ]);
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('booking.control-center.booking-resource-manager', BookingResourceManager::class);
        Livewire::component('booking.storefront.booking-widget', BookingWidget::class);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('booking.resources', 'Booking Resources', 'control-center.booking.resources', 'Booking', 'booking.view', 'tenant', 'calendar', 10));
    }
}
