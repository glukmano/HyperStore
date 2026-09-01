<?php

declare(strict_types=1);

namespace Modules\Checkout;

use App\Core\Modular\ModuleServiceProvider;
use Modules\Checkout\Commands\CleanupExpiredCheckoutsCommand;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Contracts\CheckoutPrerequisiteResolverInterface;
use Modules\Checkout\Services\CheckoutExpirationService;
use Modules\Checkout\Services\CheckoutIdempotencyService;
use Modules\Checkout\Services\CheckoutInventoryReservationOrchestrator;
use Modules\Checkout\Services\CheckoutOrchestrator;
use Modules\Checkout\Services\CheckoutOwnershipService;
use Modules\Checkout\Services\CheckoutPrerequisiteResolver;
use Modules\Checkout\Services\CheckoutPricingOrchestrator;
use Modules\Checkout\Services\CheckoutShippingOrchestrator;
use Modules\Checkout\Services\CheckoutStateMachineService;

class CheckoutServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->bind(CheckoutPrerequisiteResolverInterface::class, CheckoutPrerequisiteResolver::class);
        $this->app->bind(CheckoutOrchestratorInterface::class, CheckoutOrchestrator::class);
        $this->app->singleton(CheckoutOwnershipService::class);
        $this->app->singleton(CheckoutPricingOrchestrator::class);
        $this->app->singleton(CheckoutShippingOrchestrator::class);
        $this->app->singleton(CheckoutInventoryReservationOrchestrator::class);
        $this->app->singleton(CheckoutIdempotencyService::class);
        $this->app->singleton(CheckoutStateMachineService::class);
        $this->app->singleton(CheckoutExpirationService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupExpiredCheckoutsCommand::class,
            ]);
        }
    }
}
