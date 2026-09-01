<?php

declare(strict_types=1);

namespace Modules\Cart;

use App\Core\Modular\ModuleServiceProvider;
use Modules\Cart\Commands\CleanupExpiredCartsCommand;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Services\CartOwnershipService;
use Modules\Cart\Services\CartService;

class CartServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->bind(CartServiceInterface::class, CartService::class);
        $this->app->singleton(CartOwnershipService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupExpiredCartsCommand::class,
            ]);
        }
    }
}
