<?php

declare(strict_types=1);

namespace Modules\Payment;

use App\Core\Modular\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Order\Events\OrderCancelled;
use Modules\Payment\Contracts\PaymentConcurrencyBarrierInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Contracts\PaymentIdempotencyServiceInterface;
use Modules\Payment\Listeners\OrderCancelledListener;
use Modules\Payment\Providers\FakePaymentGateway;
use Modules\Payment\Registries\PaymentGatewayRegistry;
use Modules\Payment\Services\NoOpPaymentConcurrencyBarrier;
use Modules\Payment\Services\PaymentCancellationService;
use Modules\Payment\Services\PaymentCaptureService;
use Modules\Payment\Services\PaymentIdempotencyService;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\Payment\Services\PaymentRefundService;

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

        $this->app->singleton(PaymentGatewayRegistryInterface::class, function (): PaymentGatewayRegistryInterface {
            $registry = new PaymentGatewayRegistry;
            $fakeGateway = new FakePaymentGateway;
            $registry->register($fakeGateway);
            $registry->setDefaultProvider('fake');

            return $registry;
        });

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
    }
}
