<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use App\Core\Localization\Middleware\SetLocaleAndDirectionMiddleware;
use App\Core\Plugin\Livewire\PluginDetail;
use App\Core\Plugin\Livewire\PluginList;
use App\Core\Stores\Contracts\StoreCreationServiceInterface;
use App\Core\SuperAdmin\Contracts\ControlCenterMutationExecutorInterface;
use App\Core\SuperAdmin\Contracts\OfficialExtensionGovernanceServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformHealthServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformReleaseServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformSaasPlanMutationServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformSettingsServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLifecycleServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantMembershipServiceInterface;
use App\Core\SuperAdmin\Http\Middleware\ControlCenterContextMiddleware;
use App\Core\SuperAdmin\Http\Middleware\EnsureSuperAdminMiddleware;
use App\Core\SuperAdmin\Models\OfficialExtension;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Theme\Http\Middleware\ResolveStorefrontThemeMiddleware;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Storefront\MessageAttachmentController;
use App\Livewire\ControlCenter\ChannelManager;
use App\Livewire\ControlCenter\DashboardOverview;
use App\Livewire\ControlCenter\MarketManager;
use App\Livewire\ControlCenter\StoreManager;
use App\Livewire\ControlCenter\TenantSettingsManager;
use App\Livewire\ControlCenter\UserRoleManager;
use App\Livewire\Storefront\Account\ConversationThread;
use App\Livewire\Storefront\Account\GiftRegistriesPage;
use App\Livewire\Storefront\Account\GiftRegistryEditor;
use App\Livewire\Storefront\Account\MessagesInbox;
use App\Livewire\Storefront\Account\NotificationPreferencesPage;
use App\Livewire\Storefront\Account\RecentlyViewedPage;
use App\Livewire\Storefront\Account\WishlistPage;
use App\Livewire\Storefront\CartPage;
use App\Livewire\Storefront\CategoryPage;
use App\Livewire\Storefront\CheckoutPage;
use App\Livewire\Storefront\CmsPage;
use App\Livewire\Storefront\ComparePage;
use App\Livewire\Storefront\GiftRegistryPublicPage;
use App\Livewire\Storefront\Home;
use App\Livewire\Storefront\OrderConfirmationPage;
use App\Livewire\Storefront\OrderLookupPage;
use App\Livewire\Storefront\ProductPage;
use App\Livewire\Storefront\SearchResultsPage;
use App\Livewire\Storefront\VendorStorefrontPage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Cms\Livewire\BannerManager;
use Modules\Cms\Livewire\BlogManager;
use Modules\Cms\Livewire\FaqManager;
use Modules\Cms\Livewire\MediaLibraryManager;
use Modules\Cms\Livewire\MenuManager;
use Modules\Cms\Livewire\PageEditor;
use Modules\Cms\Livewire\PageManager;
use Modules\Cms\Livewire\RedirectManager;
use Modules\Messaging\Livewire\MessagingModerationManager;
use Modules\Reviews\Livewire\QaModerationManager;
use Modules\Reviews\Livewire\ReviewModerationManager;
use Modules\Reviews\Livewire\VendorReviewModerationManager;
use Modules\Search\Livewire\MerchandisingManager;
use Modules\Search\Livewire\SearchAnalyticsDashboard;
use Modules\Search\Livewire\SynonymManager;
use Modules\Seo\Livewire\SeoSettingsManager;

// ── Health check (Minimal Public Liveness) ────────────────────────────────────
Route::get('/up', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// ── Authentication (minimal first-party web session entry) ─────────────────────
Route::middleware(['web', SetLocaleAndDirectionMiddleware::class, ResolveContextMiddleware::class])->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

    // Storefront customer self-registration / password reset / email verification (Phase-17).
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');

    Route::middleware('auth')->group(function (): void {
        Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
        Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
    });
});

// ── Storefront (public, theme-rendered) ────────────────────────────────────────
Route::middleware([SetLocaleAndDirectionMiddleware::class, ResolveStorefrontThemeMiddleware::class])
    ->group(function (): void {
        Route::get('/', Home::class)->name('storefront.home');
        Route::get('/c/{code}', CategoryPage::class)->name('storefront.category');
        Route::get('/p/{sku}', ProductPage::class)->name('storefront.product');
        Route::get('/cart', CartPage::class)->name('storefront.cart');
        Route::get('/checkout', CheckoutPage::class)->name('storefront.checkout');
        Route::get('/order/confirmation/{orderNumber}', OrderConfirmationPage::class)->name('storefront.order-confirmation');
        Route::get('/order/lookup', OrderLookupPage::class)->name('storefront.order-lookup');
        Route::get('/vendor/{slug}', VendorStorefrontPage::class)->name('storefront.vendor');
        Route::get('/search', SearchResultsPage::class)->name('storefront.search');
        Route::get('/pages/{slug}', CmsPage::class)->name('storefront.cms-page');

        // Guest-or-authenticated Customer Engagement surfaces (Phase-17 delta).
        Route::get('/compare', ComparePage::class)->name('storefront.compare');
        Route::get('/registry/{shareToken}', GiftRegistryPublicPage::class)->name('registry.public');
        Route::get('/message-attachments/{attachment}', [MessageAttachmentController::class, 'show'])
            ->middleware('auth')
            ->name('storefront.message-attachments.show');

        Route::get('/account/wishlist', WishlistPage::class)->name('account.wishlist');
        Route::get('/account/wishlist/shared/{shareToken}', WishlistPage::class)->name('account.wishlist.shared');
        Route::get('/account/recently-viewed', RecentlyViewedPage::class)->name('account.recently-viewed');

        Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
            Route::get('/gift-registries', GiftRegistriesPage::class)->name('gift-registries.index');
            Route::get('/gift-registries/{registry}', GiftRegistryEditor::class)->name('gift-registries.show');
            Route::get('/messages', MessagesInbox::class)->name('messages.index');
            Route::get('/messages/{conversation}', ConversationThread::class)->name('messages.show');
            Route::get('/notifications', NotificationPreferencesPage::class)->name('notifications');
        });
    });

// ── Control Center · Platform Admin Screens (Stores, Markets, Channels, Settings, Users) ──
Route::middleware(['web', 'auth', SetLocaleAndDirectionMiddleware::class, ResolveContextMiddleware::class])
    ->prefix('control-center/platform')
    ->name('control-center.platform.')
    ->group(function () {
        Route::get('/stores', StoreManager::class)->name('stores');
        Route::get('/markets', MarketManager::class)->name('markets');
        Route::get('/channels', ChannelManager::class)->name('channels');
        Route::get('/settings', TenantSettingsManager::class)->name('settings');
        Route::get('/users', UserRoleManager::class)->name('users');

        Route::prefix('plugins')->name('plugins.')->group(function () {
            Route::get('/', PluginList::class)->name('index');
            Route::get('/{pluginId}', PluginDetail::class)->name('show');
        });

        Route::get('/reviews', ReviewModerationManager::class)->name('reviews.index');
        Route::get('/vendor-reviews', VendorReviewModerationManager::class)->name('vendor-reviews.index');
        Route::get('/qa', QaModerationManager::class)->name('qa.index');
        Route::get('/messaging', MessagingModerationManager::class)->name('messaging.index');

        Route::prefix('cms/pages')->name('cms.pages.')->group(function () {
            Route::get('/', PageManager::class)->name('index');
            Route::get('/{page}', PageEditor::class)->name('edit');
        });

        Route::get('/cms/blog', BlogManager::class)->name('cms.blog.index');
        Route::get('/cms/faq', FaqManager::class)->name('cms.faq.index');
        Route::get('/cms/menus', MenuManager::class)->name('cms.menus.index');
        Route::get('/cms/banners', BannerManager::class)->name('cms.banners.index');
        Route::get('/cms/media', MediaLibraryManager::class)->name('cms.media.index');
        Route::get('/cms/redirects', RedirectManager::class)->name('cms.redirects.index');

        Route::get('/seo/settings', SeoSettingsManager::class)->name('seo.settings');

        Route::get('/search/synonyms', SynonymManager::class)->name('search.synonyms.index');
        Route::get('/search/merchandising', MerchandisingManager::class)->name('search.merchandising.index');
        Route::get('/search/analytics', SearchAnalyticsDashboard::class)->name('search.analytics');
    });

// ── Control Center ────────────────────────────────────────────────────────────
Route::middleware([SetLocaleAndDirectionMiddleware::class])
    ->prefix('control-center')
    ->group(function () {
        // Authenticated Context-Governed Routes
        Route::middleware(['auth', ControlCenterContextMiddleware::class])->group(function () {
            Route::get('/', DashboardOverview::class)->name('control-center.dashboard');
            Route::get('/{tenant}', DashboardOverview::class)->name('control-center.tenant.dashboard');

            // Tenant Administration Routes (Tenant Admin / Staff / Impersonated)
            Route::post('/{tenant}/stores', function (Tenant $tenant, Request $request, StoreCreationServiceInterface $storeService, ControlCenterMutationExecutorInterface $executor) {
                /** @var array<string, mixed> $validated */
                $validated = $request->validate([
                    'name' => 'required|string|max:255',
                    'slug' => 'nullable|string|max:255',
                ]);

                $store = $executor->execute($request, 'create_store', function (int $effectiveUserId) use ($tenant, $validated, $storeService) {
                    return $storeService->createStore($tenant->id, $validated, $effectiveUserId);
                });

                return response()->json(['store' => $store], 201);
            })->name('control-center.tenant.stores.create');

            Route::post('/{tenant}/memberships/{userId}/revoke', function (Tenant $tenant, int $userId, Request $request, TenantMembershipServiceInterface $membershipService, ControlCenterMutationExecutorInterface $executor) {
                $membership = $executor->execute($request, 'revoke_membership', function (int $effectiveUserId) use ($tenant, $userId, $membershipService) {
                    return $membershipService->revokeMembership($tenant->id, $userId, $effectiveUserId);
                });

                return response()->json(['membership' => $membership]);
            })->name('control-center.tenant.memberships.revoke');

            Route::post('/{tenant}/credentials', function (Tenant $tenant, Request $request, ControlCenterMutationExecutorInterface $executor) {
                $result = $executor->execute($request, 'credential_mutation', function (int $effectiveUserId) {
                    return ['status' => 'mutated', 'effective_user_id' => $effectiveUserId];
                });

                return response()->json($result);
            })->name('control-center.tenant.credentials.mutate');

            // Super Admin Only Area
            Route::middleware([EnsureSuperAdminMiddleware::class])
                ->prefix('super-admin')
                ->group(function () {
                    // Authenticated Super Admin Health Diagnostics
                    Route::get('/health', function (Request $request, PlatformHealthServiceInterface $healthService, ControlCenterMutationExecutorInterface $executor) {
                        $diagnostics = $executor->executeSuperAdmin($request, fn () => $healthService->checkHealth());

                        return response()->json($diagnostics);
                    })->name('control-center.health');

                    Route::get('/dashboard', DashboardOverview::class)->name('control-center.super-admin.dashboard');

                    // Tenant Lifecycle Operations
                    Route::post('/tenants/{tenant}/suspend', function (Tenant $tenant, Request $request, TenantLifecycleServiceInterface $lifecycleService, ControlCenterMutationExecutorInterface $executor) {
                        $reason = (string) $request->input('reason', 'Administrative suspension');
                        $result = $executor->executeSuperAdmin($request, fn () => $lifecycleService->suspend($tenant->id, $reason));

                        return response()->json(['tenant' => $result]);
                    })->name('control-center.super-admin.tenants.suspend');

                    Route::post('/tenants/{tenant}/activate', function (Tenant $tenant, Request $request, TenantLifecycleServiceInterface $lifecycleService, ControlCenterMutationExecutorInterface $executor) {
                        $result = $executor->executeSuperAdmin($request, fn () => $lifecycleService->activate($tenant->id));

                        return response()->json(['tenant' => $result]);
                    })->name('control-center.super-admin.tenants.activate');

                    // SaaS Plan & License Operations
                    Route::post('/plans/{plan}/limits', function (PlatformSaasPlan $plan, Request $request, PlatformSaasPlanMutationServiceInterface $planService, ControlCenterMutationExecutorInterface $executor) {
                        /** @var array<string, int> $limits */
                        $limits = (array) $request->input('limits', []);
                        $result = $executor->executeSuperAdmin($request, fn () => $planService->updateHardLimits($plan->id, $limits));

                        return response()->json(['plan' => $result]);
                    })->name('control-center.super-admin.plans.limits');

                    Route::post('/licenses/{tenant}/overrides', function (Tenant $tenant, Request $request, TenantLicenseServiceInterface $licenseService, ControlCenterMutationExecutorInterface $executor) {
                        /** @var array<string, int> $limits */
                        $limits = (array) $request->input('override_limits', []);
                        /** @var array<string, mixed> $features */
                        $features = (array) $request->input('override_features', []);
                        $result = $executor->executeSuperAdmin($request, fn () => $licenseService->updateOverrides($tenant->id, $limits, $features));

                        return response()->json(['license' => $result]);
                    })->name('control-center.super-admin.licenses.overrides');

                    // Releases, Extensions, Settings
                    Route::post('/releases', function (Request $request, PlatformReleaseServiceInterface $releaseService, ControlCenterMutationExecutorInterface $executor) {
                        /** @var array{version: string, channel: string, release_notes: string, compatibility?: array<string, mixed>} $validated */
                        $validated = $request->validate([
                            'version' => 'required|string|max:50',
                            'channel' => 'required|string|max:30',
                            'release_notes' => 'required|string',
                            'compatibility' => 'nullable|array',
                        ]);

                        $result = $executor->executeSuperAdmin($request, fn () => $releaseService->createRelease(
                            $validated['version'],
                            $validated['channel'],
                            $validated['release_notes'],
                            $validated['compatibility'] ?? []
                        ));

                        return response()->json(['release' => $result], 201);
                    })->name('control-center.super-admin.releases.create');

                    Route::post('/extensions/{extension}/approve', function (OfficialExtension $extension, Request $request, OfficialExtensionGovernanceServiceInterface $extensionService, ControlCenterMutationExecutorInterface $executor) {
                        $version = (string) $request->input('approved_version', '1.0.0');
                        $result = $executor->executeSuperAdmin($request, fn () => $extensionService->approveExtension($extension->id, $version));

                        return response()->json(['extension' => $result]);
                    })->name('control-center.super-admin.extensions.approve');

                    Route::post('/settings', function (Request $request, PlatformSettingsServiceInterface $settingsService, ControlCenterMutationExecutorInterface $executor) {
                        /** @var array{key: string, value: string, is_encrypted?: bool} $validated */
                        $validated = $request->validate([
                            'key' => 'required|string|max:100',
                            'value' => 'required|string',
                            'is_encrypted' => 'boolean',
                        ]);

                        /** @var User $user */
                        $user = $request->user();

                        $result = $executor->executeSuperAdmin($request, fn () => $settingsService->set(
                            $validated['key'],
                            $validated['value'],
                            (bool) ($validated['is_encrypted'] ?? false),
                            $user->id
                        ));

                        return response()->json(['setting' => $result]);
                    })->name('control-center.super-admin.settings.set');
                });
        });
    });
