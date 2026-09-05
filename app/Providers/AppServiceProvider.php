<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Audit\AuditManager;
use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Core\Channels\Contracts\StoreChannelEligibilityInterface;
use App\Core\Channels\Services\StoreChannelEligibilityService;
use App\Core\Context\ContextManager;
use App\Core\Context\Contracts\GeoProviderInterface;
use App\Core\Context\Contracts\RegionalPreferenceProviderInterface;
use App\Core\Context\Services\NullGeoProvider;
use App\Core\Context\Services\TrustedHeaderGeoProvider;
use App\Core\Customers\CustomerScopeService;
use App\Core\Features\Contracts\FeatureManagerInterface;
use App\Core\Features\FeatureManager;
use App\Core\Localization\Contracts\LocaleManagerInterface;
use App\Core\Localization\LocaleManager;
use App\Core\Modular\Commands\ModuleListCommand;
use App\Core\Modular\Contracts\ModuleKernelInterface;
use App\Core\Modular\Contracts\ModuleRegistryInterface;
use App\Core\Modular\ModuleKernel;
use App\Core\Modular\ModuleRegistry;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use App\Core\Navigation\NavigationRegistry;
use App\Core\Plugin\Console\Commands\PluginDisableCommand;
use App\Core\Plugin\Console\Commands\PluginDoctorCommand;
use App\Core\Plugin\Console\Commands\PluginEnableCommand;
use App\Core\Plugin\Console\Commands\PluginInspectCommand;
use App\Core\Plugin\Console\Commands\PluginInstallCommand;
use App\Core\Plugin\Console\Commands\PluginListCommand;
use App\Core\Plugin\Console\Commands\PluginUninstallCommand;
use App\Core\Plugin\Console\Commands\PluginUpdateCommand;
use App\Core\Plugin\Contracts\PluginCodeSwapperInterface;
use App\Core\Plugin\Contracts\PluginRegistryInterface;
use App\Core\Plugin\PluginKernel;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginComposerDependencyChecker;
use App\Core\Plugin\Services\PluginLifecycleService;
use App\Core\Plugin\Services\PluginRenameCodeSwapper;
use App\Core\Plugin\Services\PluginSignatureVerifier;
use App\Core\Plugin\Services\PluginZipInstaller;
use App\Core\Routing\DomainAddressingService;
use App\Core\Support\ContentSanitizer;
use App\Core\Support\Contracts\ContentSanitizerInterface;
use App\Core\Theme\Contracts\ThemeRegistryInterface;
use App\Core\Theme\Contracts\ThemeResolverInterface;
use App\Core\Theme\DTOs\ThemeManifest;
use App\Core\Theme\ThemeRegistry;
use App\Core\Theme\ThemeResolver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Cart\Console\Commands\SendAbandonedCartRemindersCommand;
use Modules\Cms\BlockTypeRegistry;
use Modules\Cms\Contracts\BlockTypeRegistryInterface;
use Modules\Cms\DTOs\BlockTypeDefinition;
use Modules\Customers\Listeners\CaptureReferralSignupListener;
use Modules\Customers\Listeners\CheckBackInStockSubscriptions;
use Modules\Customers\Listeners\CheckPriceDropSubscriptions;
use Modules\Customers\Listeners\QualifyCustomerReferralOnOrderPaidListener;
use Modules\Customers\Listeners\RecordGiftRegistryPurchasesOnOrderCompletion;
use Modules\Customers\Services\CustomerRegionalPreferenceProvider;
use Modules\Inventory\Events\StockReplenished;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;
use Modules\Pricing\Events\PriceChanged;
use Modules\Promotions\Listeners\EarnLoyaltyPointsOnOrderPaidListener;
use Modules\Promotions\Listeners\ReverseLoyaltyPointsOnRefundListener;
use Modules\Reviews\Contracts\RatingAggregateReaderInterface;
use Modules\Reviews\Events\ProductReviewApproved;
use Modules\Reviews\Events\ProductReviewRetracted;
use Modules\Reviews\Events\VendorReviewApproved;
use Modules\Reviews\Events\VendorReviewRetracted;
use Modules\Reviews\Listeners\RecomputeProductRatingAggregate;
use Modules\Reviews\Listeners\RecomputeVendorRatingAggregate;
use Modules\Reviews\Services\RatingAggregateService;
use Modules\Search\Console\Commands\SyncSearchIndexSettingsCommand;
use Modules\Search\Contracts\SearchServiceInterface;
use Modules\Search\Services\ScoutSearchService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StoreChannelEligibilityInterface::class, StoreChannelEligibilityService::class);
        $this->app->singleton(ModuleRegistryInterface::class, ModuleRegistry::class);

        $this->app->singleton(ModuleKernelInterface::class, function ($app) {
            return new ModuleKernel(
                app: $app,
                registry: $app->make(ModuleRegistryInterface::class),
                modulesBasePath: base_path('modules'),
            );
        });

        $this->app->scoped(ContextManager::class);
        $this->app->singleton(LocaleManagerInterface::class, LocaleManager::class);
        $this->app->bind(
            RegionalPreferenceProviderInterface::class,
            CustomerRegionalPreferenceProvider::class
        );
        $this->app->singleton(GeoProviderInterface::class, function () {
            $configured = (array) config('platform.trusted_geo_proxies', []);
            $header = config('platform.geo_country_header');

            return ($configured !== [] && is_string($header) && $header !== '')
                ? new TrustedHeaderGeoProvider
                : new NullGeoProvider;
        });
        $this->app->singleton(FeatureManagerInterface::class, FeatureManager::class);
        $this->app->singleton(AuditManagerInterface::class, AuditManager::class);
        $this->app->singleton(DomainAddressingService::class);
        $this->app->singleton(CustomerScopeService::class);
        $this->app->singleton(ContentSanitizerInterface::class, ContentSanitizer::class);
        $this->app->singleton(RatingAggregateReaderInterface::class, RatingAggregateService::class);
        $this->app->singleton(BlockTypeRegistryInterface::class, BlockTypeRegistry::class);
        $this->app->singleton(SearchServiceInterface::class, ScoutSearchService::class);
        $this->app->singleton(NavigationRegistryInterface::class, NavigationRegistry::class);
        $this->app->singleton(ThemeRegistryInterface::class, ThemeRegistry::class);
        $this->app->singleton(ThemeResolverInterface::class, ThemeResolver::class);

        $this->app->singleton(PluginRegistryInterface::class, PluginRegistry::class);
        $this->app->singleton(PluginSignatureVerifier::class);
        $this->app->singleton(PluginZipInstaller::class);
        $this->app->singleton(PluginComposerDependencyChecker::class);
        $this->app->singleton(PluginCodeSwapperInterface::class, PluginRenameCodeSwapper::class);
        $this->app->singleton(PluginLifecycleService::class);
        $this->app->singleton(PluginKernel::class, function ($app) {
            return new PluginKernel(
                app: $app,
                registry: $app->make(PluginRegistryInterface::class),
                pluginsBasePath: base_path('plugins'),
                auditManager: $app->make(AuditManagerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        Event::listen(Registered::class, SendEmailVerificationNotification::class);
        Event::listen(Registered::class, CaptureReferralSignupListener::class);
        Event::listen(OrderStatusChanged::class, RecordGiftRegistryPurchasesOnOrderCompletion::class);
        Event::listen(OrderStatusChanged::class, QualifyCustomerReferralOnOrderPaidListener::class);
        Event::listen(OrderStatusChanged::class, EarnLoyaltyPointsOnOrderPaidListener::class);
        Event::listen(PaymentRefunded::class, ReverseLoyaltyPointsOnRefundListener::class);
        Event::listen(PaymentPartiallyRefunded::class, ReverseLoyaltyPointsOnRefundListener::class);
        Event::listen(PriceChanged::class, CheckPriceDropSubscriptions::class);
        Event::listen(StockReplenished::class, CheckBackInStockSubscriptions::class);
        Event::listen(ProductReviewApproved::class, RecomputeProductRatingAggregate::class);
        Event::listen(ProductReviewRetracted::class, RecomputeProductRatingAggregate::class);
        Event::listen(VendorReviewApproved::class, RecomputeVendorRatingAggregate::class);
        Event::listen(VendorReviewRetracted::class, RecomputeVendorRatingAggregate::class);

        $this->registerFirstPartyBlockTypes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ModuleListCommand::class,
                PluginListCommand::class,
                PluginInspectCommand::class,
                PluginInstallCommand::class,
                PluginEnableCommand::class,
                PluginDisableCommand::class,
                PluginUpdateCommand::class,
                PluginUninstallCommand::class,
                PluginDoctorCommand::class,
                SyncSearchIndexSettingsCommand::class,
                SendAbandonedCartRemindersCommand::class,
            ]);
        }

        $navigation = $this->app->make(NavigationRegistryInterface::class);
        $navigation->register(new NavigationItem(
            key: 'dashboard',
            label: 'Dashboard',
            routeName: 'control-center.dashboard',
            group: 'Overview',
            context: 'all',
            icon: '🏠',
            order: 0,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-stores',
            label: 'Stores',
            routeName: 'control-center.platform.stores',
            group: 'Platform',
            permission: 'stores.view',
            context: 'tenant',
            icon: '🏬',
            order: 10,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-markets',
            label: 'Markets',
            routeName: 'control-center.platform.markets',
            group: 'Platform',
            permission: 'markets.view',
            context: 'tenant',
            icon: '🌍',
            order: 20,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-channels',
            label: 'Channels',
            routeName: 'control-center.platform.channels',
            group: 'Platform',
            permission: 'channels.view',
            context: 'tenant',
            icon: '📡',
            order: 30,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-languages',
            label: 'Languages',
            routeName: 'control-center.platform.languages',
            group: 'Platform',
            permission: 'locales.view',
            context: 'tenant',
            icon: '🈯',
            order: 32,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-countries',
            label: 'Countries',
            routeName: 'control-center.platform.countries',
            group: 'Platform',
            permission: 'countries.view',
            context: 'tenant',
            icon: '🗺️',
            order: 34,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-currencies',
            label: 'Currencies',
            routeName: 'control-center.platform.currencies',
            group: 'Platform',
            permission: 'currencies.view',
            context: 'tenant',
            icon: '💱',
            order: 36,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-domains',
            label: 'Domains',
            routeName: 'control-center.platform.domains',
            group: 'Platform',
            permission: 'domains.view',
            context: 'tenant',
            icon: '🔗',
            order: 38,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-settings',
            label: 'Settings',
            routeName: 'control-center.platform.settings',
            group: 'Platform',
            permission: 'settings.manage',
            context: 'tenant',
            icon: '⚙️',
            order: 40,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-users',
            label: 'Users & Roles',
            routeName: 'control-center.platform.users',
            group: 'Platform',
            permission: 'users.view',
            context: 'tenant',
            icon: '👥',
            order: 50,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-plugins',
            label: 'Plugins',
            routeName: 'control-center.platform.plugins.index',
            group: 'Platform',
            permission: 'plugins.view',
            context: 'tenant',
            icon: '🧩',
            order: 60,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-reviews',
            label: 'Reviews',
            routeName: 'control-center.platform.reviews.index',
            group: 'Platform',
            permission: 'reviews.view',
            context: 'tenant',
            icon: '⭐',
            order: 70,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-cms-pages',
            label: 'CMS Pages',
            routeName: 'control-center.platform.cms.pages.index',
            group: 'Platform',
            permission: 'cms.view',
            context: 'tenant',
            icon: '📄',
            order: 80,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-vendor-reviews',
            label: 'Vendor Reviews',
            routeName: 'control-center.platform.vendor-reviews.index',
            group: 'Platform',
            permission: 'reviews.view',
            context: 'tenant',
            icon: '⭐',
            order: 71,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-qa',
            label: 'Q&A Moderation',
            routeName: 'control-center.platform.qa.index',
            group: 'Platform',
            permission: 'reviews.view',
            context: 'tenant',
            icon: '❓',
            order: 72,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-messaging',
            label: 'Messaging',
            routeName: 'control-center.platform.messaging.index',
            group: 'Platform',
            permission: 'messaging.moderate',
            context: 'tenant',
            icon: '✉️',
            order: 73,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-cms-blog',
            label: 'Blog',
            routeName: 'control-center.platform.cms.blog.index',
            group: 'Platform',
            permission: 'cms.view',
            context: 'tenant',
            icon: '📝',
            order: 81,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-cms-faq',
            label: 'FAQ',
            routeName: 'control-center.platform.cms.faq.index',
            group: 'Platform',
            permission: 'cms.view',
            context: 'tenant',
            icon: '💬',
            order: 82,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-cms-menus',
            label: 'Menus',
            routeName: 'control-center.platform.cms.menus.index',
            group: 'Platform',
            permission: 'cms.view',
            context: 'tenant',
            icon: '🧭',
            order: 83,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-cms-banners',
            label: 'Banners',
            routeName: 'control-center.platform.cms.banners.index',
            group: 'Platform',
            permission: 'cms.view',
            context: 'tenant',
            icon: '🖼️',
            order: 84,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-cms-media',
            label: 'Media Library',
            routeName: 'control-center.platform.cms.media.index',
            group: 'Platform',
            permission: 'cms.view',
            context: 'tenant',
            icon: '🗂️',
            order: 85,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-cms-redirects',
            label: 'Redirects',
            routeName: 'control-center.platform.cms.redirects.index',
            group: 'Platform',
            permission: 'cms.view',
            context: 'tenant',
            icon: '↪️',
            order: 86,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-seo-settings',
            label: 'SEO Settings',
            routeName: 'control-center.platform.seo.settings',
            group: 'Platform',
            permission: 'seo.manage',
            context: 'tenant',
            icon: '🔍',
            order: 90,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-search-synonyms',
            label: 'Search Synonyms',
            routeName: 'control-center.platform.search.synonyms.index',
            group: 'Platform',
            permission: 'search.manage',
            context: 'tenant',
            icon: '🔤',
            order: 91,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-search-merchandising',
            label: 'Search Merchandising',
            routeName: 'control-center.platform.search.merchandising.index',
            group: 'Platform',
            permission: 'search.manage',
            context: 'tenant',
            icon: '📌',
            order: 92,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-search-analytics',
            label: 'Search Analytics',
            routeName: 'control-center.platform.search.analytics',
            group: 'Platform',
            permission: 'search.manage',
            context: 'tenant',
            icon: '📊',
            order: 93,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-customer-referrals',
            label: 'Customer Referrals',
            routeName: 'control-center.platform.customers.referrals',
            group: 'Platform',
            permission: 'customers.view',
            context: 'tenant',
            icon: '🤝',
            order: 94,
        ));

        $this->app->make(ThemeRegistryInterface::class)->register(
            ThemeManifest::fromJsonFile(base_path('themes/default/theme.json'))
        );
        // Unconditional boot-time default so `theme::` resolves outside storefront
        // requests too (console, static analysis); ResolveStorefrontThemeMiddleware
        // replaces this with the Store-resolved chain per-request.
        View::addNamespace('theme', base_path('themes/default'));

        $kernel = $this->app->make(ModuleKernelInterface::class);
        $kernel->discover();
        $kernel->registerModules();
        $kernel->bootModules();

        // Plugins load strictly after all Modules (ADR-0006, ADR-0133).
        $pluginKernel = $this->app->make(PluginKernel::class);
        $pluginKernel->discover();
        $pluginKernel->registerPlugins();
        $pluginKernel->bootPlugins();
    }

    /**
     * Five first-party block types only (ADR-0137) — deliberately minimal.
     * Plugins may register additional block types the same way, via
     * BlockTypeRegistryInterface::register() inside their own
     * PluginServiceProvider::boot().
     */
    private function registerFirstPartyBlockTypes(): void
    {
        $registry = $this->app->make(BlockTypeRegistryInterface::class);

        $registry->register(new BlockTypeDefinition(
            key: 'rich_text',
            label: 'Rich Text',
            configSchema: ['html' => ['nullable', 'string']],
            viewPath: 'cms.blocks.rich-text',
            icon: '📝',
        ));

        $registry->register(new BlockTypeDefinition(
            key: 'hero',
            label: 'Hero',
            configSchema: [
                'heading' => ['nullable', 'string', 'max:255'],
                'subheading' => ['nullable', 'string', 'max:500'],
                'cta_text' => ['nullable', 'string', 'max:100'],
                'cta_url' => ['nullable', 'string', 'max:500'],
            ],
            viewPath: 'cms.blocks.hero',
            icon: '🖼️',
        ));

        $registry->register(new BlockTypeDefinition(
            key: 'image_gallery',
            label: 'Image Gallery',
            configSchema: ['image_urls' => ['nullable', 'array'], 'image_urls.*' => ['string']],
            viewPath: 'cms.blocks.image-gallery',
            icon: '🖼️',
        ));

        $registry->register(new BlockTypeDefinition(
            key: 'product_grid',
            label: 'Product Grid',
            configSchema: ['category_id' => ['nullable', 'integer'], 'limit' => ['nullable', 'integer', 'min:1', 'max:24']],
            viewPath: 'cms.blocks.product-grid',
            icon: '🛍️',
        ));

        $registry->register(new BlockTypeDefinition(
            key: 'html',
            label: 'Custom HTML',
            configSchema: ['html' => ['nullable', 'string']],
            viewPath: 'cms.blocks.html',
            icon: '⚠️',
        ));
    }
}
