<?php

declare(strict_types=1);

namespace Modules\Checkout;

use App\Core\Modular\ModuleServiceProvider;
use Modules\Checkout\Commands\CleanupExpiredCheckoutsCommand;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Contracts\CheckoutPrerequisiteResolverInterface;
use Modules\Checkout\Services\CheckoutOrchestrator;
use Modules\Checkout\Services\CheckoutPrerequisiteResolver;

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
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupExpiredCheckoutsCommand::class,
            ]);
        }
    }
}
