<?php

declare(strict_types=1);

namespace Modules\Order;

use App\Core\Modular\ModuleServiceProvider;
use Modules\Order\Contracts\BusinessTimezoneResolverInterface;
use Modules\Order\Contracts\OrderCancellationServiceInterface;
use Modules\Order\Contracts\OrderCreationConcurrencyBarrierInterface;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\Contracts\OrderIdempotencyServiceInterface;
use Modules\Order\Contracts\OrderNumberGeneratorInterface;
use Modules\Order\Contracts\OrderOwnershipServiceInterface;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Contracts\OrderStateMachineServiceInterface;
use Modules\Order\Services\BusinessTimezoneResolver;
use Modules\Order\Services\NoOpOrderCreationConcurrencyBarrier;
use Modules\Order\Services\OrderCancellationService;
use Modules\Order\Services\OrderCreationService;
use Modules\Order\Services\OrderIdempotencyService;
use Modules\Order\Services\OrderNumberGenerator;
use Modules\Order\Services\OrderOwnershipService;
use Modules\Order\Services\OrderPaymentSynchronizationService;
use Modules\Order\Services\OrderStateMachineService;

class OrderServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(BusinessTimezoneResolverInterface::class, BusinessTimezoneResolver::class);
        $this->app->singleton(OrderNumberGeneratorInterface::class, OrderNumberGenerator::class);
        $this->app->singleton(OrderOwnershipServiceInterface::class, OrderOwnershipService::class);
        $this->app->singleton(OrderIdempotencyServiceInterface::class, OrderIdempotencyService::class);
        $this->app->singleton(OrderStateMachineServiceInterface::class, OrderStateMachineService::class);
        $this->app->singleton(OrderCreationConcurrencyBarrierInterface::class, NoOpOrderCreationConcurrencyBarrier::class);
        $this->app->singleton(OrderCreationServiceInterface::class, OrderCreationService::class);
        $this->app->singleton(OrderCancellationServiceInterface::class, OrderCancellationService::class);
        $this->app->singleton(OrderPaymentSynchronizationServiceInterface::class, OrderPaymentSynchronizationService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
    }
}
