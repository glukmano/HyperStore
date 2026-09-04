<?php

declare(strict_types=1);

namespace Modules\Order;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Livewire\Livewire;
use Modules\Order\Contracts\BusinessTimezoneResolverInterface;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Contracts\OrderCancellationServiceInterface;
use Modules\Order\Contracts\OrderCreationConcurrencyBarrierInterface;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\Contracts\OrderIdempotencyServiceInterface;
use Modules\Order\Contracts\OrderNumberGeneratorInterface;
use Modules\Order\Contracts\OrderOwnershipServiceInterface;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Contracts\OrderStateMachineServiceInterface;
use Modules\Order\Contracts\ReturnPhysicalDispositionServiceInterface;
use Modules\Order\Contracts\ReturnRefundOrchestratorInterface;
use Modules\Order\Contracts\ReturnRequestServiceInterface;
use Modules\Order\Contracts\SellerOrderOwnershipServiceInterface;
use Modules\Order\Contracts\ShippingRefundPolicyInterface;
use Modules\Order\Livewire\OrderDetail;
use Modules\Order\Livewire\OrderList;
use Modules\Order\Livewire\ReturnManager;
use Modules\Order\Services\BusinessTimezoneResolver;
use Modules\Order\Services\DecimalReturnAllocationService;
use Modules\Order\Services\JointShippingAllocationService;
use Modules\Order\Services\MasterOrderSplitService;
use Modules\Order\Services\NoOpOrderCreationConcurrencyBarrier;
use Modules\Order\Services\NotRefundableByDefaultShippingRefundPolicy;
use Modules\Order\Services\OrderCancellationService;
use Modules\Order\Services\OrderCreationService;
use Modules\Order\Services\OrderIdempotencyService;
use Modules\Order\Services\OrderNumberGenerator;
use Modules\Order\Services\OrderOwnershipService;
use Modules\Order\Services\OrderPaymentSynchronizationService;
use Modules\Order\Services\OrderStateMachineService;
use Modules\Order\Services\ReturnPhysicalDispositionService;
use Modules\Order\Services\ReturnRefundOrchestrator;
use Modules\Order\Services\ReturnRequestService;
use Modules\Order\Services\SellerOrderOwnershipService;

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
        $this->app->singleton(SellerOrderOwnershipServiceInterface::class, SellerOrderOwnershipService::class);
        $this->app->singleton(SellerOrderOwnershipService::class);
        $this->app->singleton(OrderIdempotencyServiceInterface::class, OrderIdempotencyService::class);
        $this->app->singleton(OrderStateMachineServiceInterface::class, OrderStateMachineService::class);
        $this->app->singleton(OrderCreationConcurrencyBarrierInterface::class, NoOpOrderCreationConcurrencyBarrier::class);
        $this->app->singleton(OrderCreationServiceInterface::class, OrderCreationService::class);
        $this->app->singleton(OrderCancellationServiceInterface::class, OrderCancellationService::class);
        $this->app->singleton(OrderPaymentSynchronizationServiceInterface::class, OrderPaymentSynchronizationService::class);
        $this->app->singleton(JointShippingAllocationService::class);
        $this->app->singleton(MasterOrderSplitServiceInterface::class, MasterOrderSplitService::class);
        $this->app->singleton(MasterOrderSplitService::class);
        $this->app->singleton(DecimalReturnAllocationService::class);
        $this->app->singleton(ReturnRequestServiceInterface::class, ReturnRequestService::class);
        $this->app->singleton(ReturnRequestService::class);
        $this->app->singleton(ShippingRefundPolicyInterface::class, NotRefundableByDefaultShippingRefundPolicy::class);
        $this->app->singleton(ReturnRefundOrchestratorInterface::class, ReturnRefundOrchestrator::class);
        $this->app->singleton(ReturnRefundOrchestrator::class);
        $this->app->singleton(ReturnPhysicalDispositionServiceInterface::class, ReturnPhysicalDispositionService::class);
        $this->app->singleton(ReturnPhysicalDispositionService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');

        $webRoutes = __DIR__.'/Routes/web.php';
        if (file_exists($webRoutes)) {
            $this->loadRoutesFrom($webRoutes);
        }

        $viewsDir = __DIR__.'/Resources/views';
        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'order');
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();
    }

    protected function registerLivewireComponents(): void
    {
        if (class_exists(Livewire::class)) {
            Livewire::component('order.order-list', OrderList::class);
            Livewire::component('order.order-detail', OrderDetail::class);
            Livewire::component('order.return-manager', ReturnManager::class);
        }
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('orders.orders', 'Orders', 'control-center.orders.orders.index', 'Orders', 'orders.view', 'tenant', '🧾', 10));
        $nav->register(new NavigationItem('orders.returns', 'Returns / RMA', 'control-center.orders.returns.index', 'Orders', 'returns.view', 'tenant', '↩️', 20));
    }
}
