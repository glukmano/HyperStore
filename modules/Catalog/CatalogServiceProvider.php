<?php

declare(strict_types=1);

namespace Modules\Catalog;

use App\Core\Modular\ModuleServiceProvider;
use Livewire\Livewire;
use Modules\Catalog\Contracts\CategoryHierarchyValidatorInterface;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\Contracts\VariantCombinatorInterface;
use Modules\Catalog\Livewire\AttributeManager;
use Modules\Catalog\Livewire\AttributeSetManager;
use Modules\Catalog\Livewire\BrandManager;
use Modules\Catalog\Livewire\CategoryManager;
use Modules\Catalog\Livewire\ProductForm;
use Modules\Catalog\Livewire\ProductList;
use Modules\Catalog\ProductTypes\AffiliateProductType;
use Modules\Catalog\ProductTypes\AuctionProductType;
use Modules\Catalog\ProductTypes\BookingProductType;
use Modules\Catalog\ProductTypes\BundleProductType;
use Modules\Catalog\ProductTypes\ConfigurableProductType;
use Modules\Catalog\ProductTypes\CustomProductType;
use Modules\Catalog\ProductTypes\DigitalProductType;
use Modules\Catalog\ProductTypes\GiftCardProductType;
use Modules\Catalog\ProductTypes\LicenseProductType;
use Modules\Catalog\ProductTypes\MadeToOrderProductType;
use Modules\Catalog\ProductTypes\MembershipProductType;
use Modules\Catalog\ProductTypes\PhysicalProductType;
use Modules\Catalog\ProductTypes\PreorderProductType;
use Modules\Catalog\ProductTypes\PrintOnDemandProductType;
use Modules\Catalog\ProductTypes\ProductTypeRegistry;
use Modules\Catalog\ProductTypes\RentalProductType;
use Modules\Catalog\ProductTypes\RfqProductType;
use Modules\Catalog\ProductTypes\ServiceProductType;
use Modules\Catalog\ProductTypes\SubscriptionProductType;
use Modules\Catalog\ProductTypes\TicketProductType;
use Modules\Catalog\ProductTypes\TopUpProductType;
use Modules\Catalog\ProductTypes\VariableProductType;
use Modules\Catalog\ProductTypes\WholesaleProductType;
use Modules\Catalog\Services\CategoryHierarchyService;
use Modules\Catalog\Services\VariantCombinatorService;

class CatalogServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(ProductTypeRegistryInterface::class, function () {
            $registry = new ProductTypeRegistry;
            $registry->register(new PhysicalProductType);
            $registry->register(new DigitalProductType);
            $registry->register(new LicenseProductType);
            $registry->register(new SubscriptionProductType);
            $registry->register(new TopUpProductType);
            $registry->register(new GiftCardProductType);
            $registry->register(new ServiceProductType);
            $registry->register(new BookingProductType);
            $registry->register(new RentalProductType);
            $registry->register(new BundleProductType);
            $registry->register(new VariableProductType);
            $registry->register(new ConfigurableProductType);
            $registry->register(new CustomProductType);
            $registry->register(new AffiliateProductType);
            $registry->register(new PreorderProductType);
            $registry->register(new MembershipProductType);
            $registry->register(new TicketProductType);
            $registry->register(new AuctionProductType);
            $registry->register(new RfqProductType);
            $registry->register(new WholesaleProductType);
            $registry->register(new MadeToOrderProductType);
            $registry->register(new PrintOnDemandProductType);

            return $registry;
        });

        $this->app->singleton(CategoryHierarchyValidatorInterface::class, CategoryHierarchyService::class);
        $this->app->singleton(VariantCombinatorInterface::class, VariantCombinatorService::class);
    }

    public function boot(): void
    {
        $routesDir = $this->getPath().'/Routes';
        if (file_exists($routesDir.'/api.php')) {
            $this->loadRoutesFrom($routesDir.'/api.php');
        }
        if (file_exists($routesDir.'/web.php')) {
            $this->loadRoutesFrom($routesDir.'/web.php');
        }

        $viewsDir = $this->getPath().'/Resources/views';
        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'catalog');
        }

        $langDir = $this->getPath().'/Resources/lang';
        if (is_dir($langDir)) {
            $this->loadTranslationsFrom($langDir, 'catalog');
        }

        $this->registerLivewireComponents();
    }

    protected function registerLivewireComponents(): void
    {
        if (class_exists(Livewire::class)) {
            Livewire::component('catalog.product-list', ProductList::class);
            Livewire::component('catalog.product-form', ProductForm::class);
            Livewire::component('catalog.category-manager', CategoryManager::class);
            Livewire::component('catalog.attribute-manager', AttributeManager::class);
            Livewire::component('catalog.attribute-set-manager', AttributeSetManager::class);
            Livewire::component('catalog.brand-manager', BrandManager::class);
        }
    }
}
