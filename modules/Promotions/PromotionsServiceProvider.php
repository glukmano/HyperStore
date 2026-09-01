<?php

declare(strict_types=1);

namespace Modules\Promotions;

use App\Core\Modular\ModuleServiceProvider;
use Livewire\Livewire;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Promotions\Actions\BuyXGetYAction;
use Modules\Promotions\Actions\FixedDiscountAction;
use Modules\Promotions\Actions\FixedPriceAction;
use Modules\Promotions\Actions\FreeShippingAction;
use Modules\Promotions\Actions\PercentageDiscountAction;
use Modules\Promotions\Conditions\BrandCondition;
use Modules\Promotions\Conditions\CategoryCondition;
use Modules\Promotions\Conditions\ChannelCondition;
use Modules\Promotions\Conditions\CouponCondition;
use Modules\Promotions\Conditions\CustomerGroupCondition;
use Modules\Promotions\Conditions\FirstOrderCondition;
use Modules\Promotions\Conditions\MarketCondition;
use Modules\Promotions\Conditions\MinCartAmountCondition;
use Modules\Promotions\Conditions\MinQuantityCondition;
use Modules\Promotions\Conditions\ProductCondition;
use Modules\Promotions\Conditions\ProductTypeCondition;
use Modules\Promotions\Conditions\StoreCondition;
use Modules\Promotions\Contracts\ShippingPromotionBenefitResolverInterface;
use Modules\Promotions\Livewire\CouponManager;
use Modules\Promotions\Livewire\PromotionManager;
use Modules\Promotions\Registries\PromotionActionRegistry;
use Modules\Promotions\Registries\PromotionConditionRegistry;
use Modules\Promotions\Services\CouponValidationService;
use Modules\Promotions\Services\PromotionRuleEngine;
use Modules\Promotions\Services\ShippingPromotionBenefitResolver;

class PromotionsServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(PromotionConditionRegistry::class, function () {
            $registry = new PromotionConditionRegistry;
            $registry->register(new MinCartAmountCondition);
            $registry->register(new MinQuantityCondition);
            $registry->register(new ProductCondition);
            $registry->register(new CategoryCondition);
            $registry->register(new BrandCondition);
            $registry->register(new ProductTypeCondition);
            $registry->register(new StoreCondition);
            $registry->register(new MarketCondition);
            $registry->register(new ChannelCondition);
            $registry->register(new CustomerGroupCondition);
            $registry->register(new FirstOrderCondition);
            $registry->register(new CouponCondition);

            return $registry;
        });

        $this->app->singleton(PromotionActionRegistry::class, function ($app) {
            $registry = new PromotionActionRegistry;
            $registry->register(new PercentageDiscountAction);
            $registry->register(new FixedDiscountAction($app->make(CurrencyConversionInterface::class)));
            $registry->register(new FixedPriceAction);
            $registry->register(new BuyXGetYAction);
            $registry->register(new FreeShippingAction);

            return $registry;
        });

        $this->app->singleton(CouponValidationService::class);
        $this->app->singleton(PromotionRuleEngine::class);
        $this->app->singleton(ShippingPromotionBenefitResolverInterface::class, ShippingPromotionBenefitResolver::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'promotions');

        $this->registerLivewireComponents();
    }

    protected function registerLivewireComponents(): void
    {
        if (class_exists(Livewire::class)) {
            Livewire::component('promotions.promotion-manager', PromotionManager::class);
            Livewire::component('promotions.coupon-manager', CouponManager::class);
        }
    }
}
