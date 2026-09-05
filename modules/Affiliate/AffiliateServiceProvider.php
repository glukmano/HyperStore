<?php

declare(strict_types=1);

namespace Modules\Affiliate;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Affiliate\Contracts\AffiliateAttributionServiceInterface;
use Modules\Affiliate\Contracts\AffiliateCommissionRuleResolverInterface;
use Modules\Affiliate\Contracts\AffiliateFraudDetectionServiceInterface;
use Modules\Affiliate\Contracts\AffiliatePayableSubledgerServiceInterface;
use Modules\Affiliate\Contracts\AffiliatePayoutServiceInterface;
use Modules\Affiliate\Contracts\AffiliateTargetResolverInterface;
use Modules\Affiliate\Listeners\ActivateAffiliateConversionOnOrderPaidListener;
use Modules\Affiliate\Listeners\ReverseAffiliateCommissionOnRefundListener;
use Modules\Affiliate\Livewire\ControlCenter\AffiliateCampaignManager;
use Modules\Affiliate\Livewire\ControlCenter\AffiliateCommissionRuleManager;
use Modules\Affiliate\Livewire\ControlCenter\AffiliateManager;
use Modules\Affiliate\Livewire\ControlCenter\AffiliatePayoutManager;
use Modules\Affiliate\Livewire\Storefront\AffiliateApplicationForm;
use Modules\Affiliate\Livewire\Storefront\AffiliateDashboard;
use Modules\Affiliate\Services\AffiliateAttributionService;
use Modules\Affiliate\Services\AffiliateCommissionRuleResolver;
use Modules\Affiliate\Services\AffiliateFraudDetectionService;
use Modules\Affiliate\Services\AffiliatePayableSubledgerService;
use Modules\Affiliate\Services\AffiliatePayoutService;
use Modules\Affiliate\Services\AffiliateTargetResolver;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;

class AffiliateServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(AffiliateTargetResolverInterface::class, AffiliateTargetResolver::class);
        $this->app->singleton(AffiliateCommissionRuleResolverInterface::class, AffiliateCommissionRuleResolver::class);
        $this->app->singleton(AffiliatePayableSubledgerServiceInterface::class, AffiliatePayableSubledgerService::class);
        $this->app->singleton(AffiliatePayoutServiceInterface::class, AffiliatePayoutService::class);
        $this->app->singleton(AffiliateFraudDetectionServiceInterface::class, AffiliateFraudDetectionService::class);
        $this->app->singleton(AffiliateAttributionServiceInterface::class, AffiliateAttributionService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $webRoutesPath = __DIR__.'/Routes/web.php';
        if (file_exists($webRoutesPath)) {
            $this->loadRoutesFrom($webRoutesPath);
        }

        $viewsDir = __DIR__.'/Resources/views';
        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'affiliate');
        }

        Event::listen(OrderStatusChanged::class, ActivateAffiliateConversionOnOrderPaidListener::class);
        Event::listen(PaymentRefunded::class, ReverseAffiliateCommissionOnRefundListener::class);
        Event::listen(PaymentPartiallyRefunded::class, ReverseAffiliateCommissionOnRefundListener::class);

        $this->registerLivewireComponents();
        $this->registerNavigation();
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('affiliate.control-center.affiliate-manager', AffiliateManager::class);
        Livewire::component('affiliate.control-center.affiliate-commission-rule-manager', AffiliateCommissionRuleManager::class);
        Livewire::component('affiliate.control-center.affiliate-campaign-manager', AffiliateCampaignManager::class);
        Livewire::component('affiliate.control-center.affiliate-payout-manager', AffiliatePayoutManager::class);
        Livewire::component('affiliate.storefront.affiliate-dashboard', AffiliateDashboard::class);
        Livewire::component('affiliate.storefront.affiliate-application-form', AffiliateApplicationForm::class);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('affiliate.affiliates', 'Affiliates', 'control-center.affiliate.affiliates', 'Marketing', 'affiliates.view', 'tenant', '🔗', 10));
        $nav->register(new NavigationItem('affiliate.campaigns', 'Campaigns', 'control-center.affiliate.campaigns', 'Marketing', 'marketing-campaigns.view', 'tenant', '📣', 20));
        $nav->register(new NavigationItem('affiliate.commission-rules', 'Commission Rules', 'control-center.affiliate.commission-rules', 'Marketing', 'affiliates.manage', 'tenant', '💸', 30));
        $nav->register(new NavigationItem('affiliate.payouts', 'Affiliate Payouts', 'control-center.affiliate.payouts', 'Marketing', 'affiliate-payouts.view', 'tenant', '💰', 40));
    }
}
