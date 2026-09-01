<?php

declare(strict_types=1);

namespace Modules\Cart;

use App\Core\Modular\ModuleServiceProvider;
use Modules\Cart\Commands\CleanupExpiredCartsCommand;
use Modules\Cart\Contracts\CartServiceInterface;
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
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupExpiredCartsCommand::class,
            ]);
        }
    }
}
