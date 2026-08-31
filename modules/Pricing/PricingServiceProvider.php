<?php

declare(strict_types=1);

namespace Modules\Pricing;

use App\Core\Modular\ModuleServiceProvider;
use Livewire\Livewire;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\Contracts\TaxCalculatorInterface;
use Modules\Pricing\Livewire\ExchangeRateManager;
use Modules\Pricing\Livewire\PriceBookManager;
use Modules\Pricing\Livewire\ProductPricingManager;
use Modules\Pricing\Livewire\TaxManager;
use Modules\Pricing\Services\CurrencyConversionService;
use Modules\Pricing\Services\PriceResolver;
use Modules\Pricing\Services\TaxCalculator;

class PricingServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(CurrencyConversionInterface::class, CurrencyConversionService::class);
        $this->app->singleton(TaxCalculatorInterface::class, TaxCalculator::class);
        $this->app->singleton(PriceResolverInterface::class, PriceResolver::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'pricing');

        $this->registerLivewireComponents();
    }

    protected function registerLivewireComponents(): void
    {
        if (class_exists(Livewire::class)) {
            Livewire::component('pricing.price-book-manager', PriceBookManager::class);
            Livewire::component('pricing.product-pricing-manager', ProductPricingManager::class);
            Livewire::component('pricing.exchange-rate-manager', ExchangeRateManager::class);
            Livewire::component('pricing.tax-manager', TaxManager::class);
        }
    }
}
