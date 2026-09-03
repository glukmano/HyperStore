<?php

declare(strict_types=1);

namespace Modules\Marketplace;

use App\Core\Modular\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Marketplace\Contracts\MarketplaceCommercialPolicyInterface;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\PayoutServiceInterface;
use Modules\Marketplace\Contracts\VendorApprovalPolicyInterface;
use Modules\Marketplace\Contracts\VendorCommissionQuoteServiceInterface;
use Modules\Marketplace\Contracts\VendorListingResolutionServiceInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\Contracts\VendorPlanSubscriptionEntitlementServiceInterface;
use Modules\Marketplace\Contracts\VendorStorefrontResolverInterface;
use Modules\Marketplace\Listeners\OrderPaidAccrueVendorPayableListener;
use Modules\Marketplace\Services\MarketplaceCommercialPolicy;
use Modules\Marketplace\Services\NoOpMarketplaceConcurrencyBarrier;
use Modules\Marketplace\Services\PayoutService;
use Modules\Marketplace\Services\VendorApprovalPolicy;
use Modules\Marketplace\Services\VendorCommissionCalculator;
use Modules\Marketplace\Services\VendorDomainVerificationService;
use Modules\Marketplace\Services\VendorInvitationService;
use Modules\Marketplace\Services\VendorListingResolutionService;
use Modules\Marketplace\Services\VendorOwnershipService;
use Modules\Marketplace\Services\VendorPayableSubledgerService;
use Modules\Marketplace\Services\VendorPlanSubscriptionEntitlementService;
use Modules\Marketplace\Services\VendorRegistrationService;
use Modules\Marketplace\Services\VendorStorefrontResolver;
use Modules\Order\Events\OrderStatusChanged;

class MarketplaceServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(MarketplaceConcurrencyBarrierInterface::class, NoOpMarketplaceConcurrencyBarrier::class);
        $this->app->singleton(MarketplaceCommercialPolicyInterface::class, MarketplaceCommercialPolicy::class);
        $this->app->singleton(VendorPlanSubscriptionEntitlementServiceInterface::class, VendorPlanSubscriptionEntitlementService::class);
        $this->app->singleton(VendorApprovalPolicyInterface::class, VendorApprovalPolicy::class);
        $this->app->singleton(VendorListingResolutionServiceInterface::class, VendorListingResolutionService::class);
        $this->app->singleton(VendorCommissionQuoteServiceInterface::class, VendorCommissionCalculator::class);
        $this->app->singleton(VendorPayableSubledgerServiceInterface::class, VendorPayableSubledgerService::class);
        $this->app->singleton(PayoutServiceInterface::class, PayoutService::class);
        $this->app->singleton(VendorStorefrontResolverInterface::class, VendorStorefrontResolver::class);

        $this->app->singleton(VendorRegistrationService::class);
        $this->app->singleton(VendorOwnershipService::class);
        $this->app->singleton(VendorInvitationService::class);
        $this->app->singleton(VendorDomainVerificationService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $routesPath = __DIR__.'/Routes/api.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        Event::listen(OrderStatusChanged::class, OrderPaidAccrueVendorPayableListener::class);
    }
}
