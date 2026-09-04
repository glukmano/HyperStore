<?php

declare(strict_types=1);

namespace Modules\Payment;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Order\Events\OrderCancelled;
use Modules\Payment\Contracts\PaymentConcurrencyBarrierInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Contracts\PaymentIdempotencyServiceInterface;
use Modules\Payment\Listeners\OrderCancelledListener;
use Modules\Payment\Livewire\PaymentDetail;
use Modules\Payment\Livewire\PaymentList;
use Modules\Payment\Providers\FakePaymentGateway;
use Modules\Payment\Registries\PaymentGatewayRegistry;
use Modules\Payment\Services\NoOpPaymentConcurrencyBarrier;
use Modules\Payment\Services\PaymentCancellationService;
use Modules\Payment\Services\PaymentCaptureService;
use Modules\Payment\Services\PaymentIdempotencyService;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\Payment\Services\PaymentRefundService;
use Modules\Payment\Services\PaymentTransactionReconciliationService;

class PaymentServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(PaymentConcurrencyBarrierInterface::class, NoOpPaymentConcurrencyBarrier::class);
        $this->app->singleton(PaymentIdempotencyServiceInterface::class, PaymentIdempotencyService::class);

        $this->app->singleton(PaymentGatewayRegistryInterface::class, function (Application $app): PaymentGatewayRegistryInterface {
            $registry = new PaymentGatewayRegistry;

            // FakePaymentGateway is strictly restricted to local and testing environments
            if ($app->environment('local', 'testing')) {
                $fakeGateway = new FakePaymentGateway;
                $registry->register($fakeGateway);
                $registry->setDefaultProvider('fake');
            }

            return $registry;
        });

        $this->app->singleton(PaymentTransactionReconciliationService::class);
        $this->app->singleton(PaymentInitiationService::class);
        $this->app->singleton(PaymentCaptureService::class);
        $this->app->singleton(PaymentRefundService::class);
        $this->app->singleton(PaymentCancellationService::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(OrderCancelled::class, OrderCancelledListener::class);

        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');

        $webRoutes = __DIR__.'/Routes/web.php';
        if (file_exists($webRoutes)) {
            $this->loadRoutesFrom($webRoutes);
        }

        $viewsDir = __DIR__.'/Resources/views';
        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'payment');
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();
    }

    protected function registerLivewireComponents(): void
    {
        if (class_exists(Livewire::class)) {
            Livewire::component('payment.payment-list', PaymentList::class);
            Livewire::component('payment.payment-detail', PaymentDetail::class);
        }
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('payments.payments', 'Payments', 'control-center.payments.index', 'Payments', 'payments.view', 'tenant', '💳', 100));
    }
}
