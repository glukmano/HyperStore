<?php

declare(strict_types=1);

namespace Modules\Promotions;

use App\Core\Modular\ModuleServiceProvider;
use Livewire\Livewire;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Promotions\Actions\BuyXGetYAction;
use Modules\Promotions\Actions\FixedDiscountAction;
use Modules\Promotions\Actions\PercentageDiscountAction;
use Modules\Promotions\Conditions\CouponCondition;
use Modules\Promotions\Conditions\MinCartAmountCondition;
use Modules\Promotions\Conditions\MinQuantityCondition;
use Modules\Promotions\Conditions\ProductCondition;
use Modules\Promotions\Livewire\CouponManager;
use Modules\Promotions\Livewire\PromotionManager;
use Modules\Promotions\Registries\PromotionActionRegistry;
use Modules\Promotions\Registries\PromotionConditionRegistry;
use Modules\Promotions\Services\PromotionRuleEngine;

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
            $registry->register(new CouponCondition);

            return $registry;
        });

        $this->app->singleton(PromotionActionRegistry::class, function ($app) {
            $registry = new PromotionActionRegistry;
            $registry->register(new PercentageDiscountAction);
            $registry->register(new FixedDiscountAction($app->make(CurrencyConversionInterface::class)));
            $registry->register(new BuyXGetYAction);

            return $registry;
        });

        $this->app->singleton(PromotionRuleEngine::class);
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
