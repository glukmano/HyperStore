<?php

declare(strict_types=1);

use App\Core\Localization\Middleware\SetLocaleAndDirectionMiddleware;
use App\Core\Stores\Contracts\StoreCreationServiceInterface;
use App\Core\SuperAdmin\Contracts\ContextualMutationAuthorizerInterface;
use App\Core\SuperAdmin\Contracts\OfficialExtensionGovernanceServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformHealthServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformReleaseServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformSaasPlanMutationServiceInterface;
use App\Core\SuperAdmin\Contracts\PlatformSettingsServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLifecycleServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantMembershipServiceInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\SuperAdmin\Http\Middleware\ControlCenterContextMiddleware;
use App\Core\SuperAdmin\Http\Middleware\EnsureSuperAdminMiddleware;
use App\Core\SuperAdmin\Models\OfficialExtension;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use App\Livewire\ControlCenter\DashboardOverview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Health check (Public liveness) ────────────────────────────────────────────
Route::get('/up', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'version' => app()->version(),
        'php' => PHP_VERSION,
        'timestamp' => now()->toIso8601String(),
    ]);
});

// ── Control Center ────────────────────────────────────────────────────────────
Route::middleware([SetLocaleAndDirectionMiddleware::class])
    ->prefix('control-center')
    ->group(function () {
        // Authenticated Context-Governed Routes
        Route::middleware([ControlCenterContextMiddleware::class])->group(function () {
            Route::get('/', DashboardOverview::class)->name('control-center.dashboard');
            Route::get('/{tenant}', DashboardOverview::class)->name('control-center.tenant.dashboard');

            // Tenant Administration Routes (Tenant Admin / Staff)
            Route::post('/{tenant}/stores', function (Tenant $tenant, Request $request, StoreCreationServiceInterface $storeService) {
                $user = $request->user();
                if ($user === null) {
                    throw UnauthorizedContextException::unauthenticated();
                }

                /** @var array<string, mixed> $validated */
                $validated = $request->validate([
                    'name' => 'required|string|max:255',
                    'slug' => 'nullable|string|max:255',
                ]);

                $store = $storeService->createStore($tenant->id, $validated, $user->id);

                return response()->json(['store' => $store], 201);
            })->name('control-center.tenant.stores.create');

            Route::post('/{tenant}/memberships/{userId}/revoke', function (Tenant $tenant, int $userId, Request $request, TenantMembershipServiceInterface $membershipService) {
                $user = $request->user();
                if ($user === null) {
                    throw UnauthorizedContextException::unauthenticated();
                }

                $membership = $membershipService->revokeMembership($tenant->id, $userId, $user->id);

                return response()->json(['membership' => $membership]);
            })->name('control-center.tenant.memberships.revoke');

            // Super Admin Only Area
            Route::middleware([EnsureSuperAdminMiddleware::class])
                ->prefix('super-admin')
                ->group(function () {
                    // Authenticated Super Admin Health Diagnostics
                    Route::get('/health', function (Request $request, PlatformHealthServiceInterface $healthService, ContextualMutationAuthorizerInterface $authorizer) {
                        $user = $request->user();
                        if ($user === null) {
                            throw UnauthorizedContextException::unauthenticated();
                        }

                        $diagnostics = $authorizer->executeSuperAdminAuthorized($user->id, fn () => $healthService->checkHealth());

                        return response()->json($diagnostics);
                    })->name('control-center.health');

                    Route::get('/dashboard', DashboardOverview::class)->name('control-center.super-admin.dashboard');

                    // Tenant Lifecycle Operations
                    Route::post('/tenants/{tenant}/suspend', function (Tenant $tenant, Request $request, TenantLifecycleServiceInterface $lifecycleService, ContextualMutationAuthorizerInterface $authorizer) {
                        $user = $request->user();
                        if ($user === null) {
                            throw UnauthorizedContextException::unauthenticated();
                        }

                        $reason = (string) $request->input('reason', 'Administrative suspension');
                        $result = $authorizer->executeSuperAdminAuthorized($user->id, fn () => $lifecycleService->suspend($tenant->id, $reason));

                        return response()->json(['tenant' => $result]);
                    })->name('control-center.super-admin.tenants.suspend');

                    Route::post('/tenants/{tenant}/activate', function (Tenant $tenant, Request $request, TenantLifecycleServiceInterface $lifecycleService, ContextualMutationAuthorizerInterface $authorizer) {
                        $user = $request->user();
                        if ($user === null) {
                            throw UnauthorizedContextException::unauthenticated();
                        }

                        $result = $authorizer->executeSuperAdminAuthorized($user->id, fn () => $lifecycleService->activate($tenant->id));

                        return response()->json(['tenant' => $result]);
                    })->name('control-center.super-admin.tenants.activate');

                    // SaaS Plan & License Operations
                    Route::post('/plans/{plan}/limits', function (PlatformSaasPlan $plan, Request $request, PlatformSaasPlanMutationServiceInterface $planService, ContextualMutationAuthorizerInterface $authorizer) {
                        $user = $request->user();
                        if ($user === null) {
                            throw UnauthorizedContextException::unauthenticated();
                        }

                        /** @var array<string, int> $limits */
                        $limits = (array) $request->input('limits', []);
                        $result = $authorizer->executeSuperAdminAuthorized($user->id, fn () => $planService->updateHardLimits($plan->id, $limits));

                        return response()->json(['plan' => $result]);
                    })->name('control-center.super-admin.plans.limits');

                    Route::post('/licenses/{tenant}/overrides', function (Tenant $tenant, Request $request, TenantLicenseServiceInterface $licenseService, ContextualMutationAuthorizerInterface $authorizer) {
                        $user = $request->user();
                        if ($user === null) {
                            throw UnauthorizedContextException::unauthenticated();
                        }

                        /** @var array<string, int> $limits */
                        $limits = (array) $request->input('override_limits', []);
                        /** @var array<string, mixed> $features */
                        $features = (array) $request->input('override_features', []);
                        $result = $authorizer->executeSuperAdminAuthorized($user->id, fn () => $licenseService->updateOverrides($tenant->id, $limits, $features));

                        return response()->json(['license' => $result]);
                    })->name('control-center.super-admin.licenses.overrides');

                    // Releases, Extensions, Settings
                    Route::post('/releases', function (Request $request, PlatformReleaseServiceInterface $releaseService, ContextualMutationAuthorizerInterface $authorizer) {
                        $user = $request->user();
                        if ($user === null) {
                            throw UnauthorizedContextException::unauthenticated();
                        }

                        /** @var array{version: string, channel: string, release_notes: string, compatibility?: array<string, mixed>} $validated */
                        $validated = $request->validate([
                            'version' => 'required|string|max:50',
                            'channel' => 'required|string|max:30',
                            'release_notes' => 'required|string',
                            'compatibility' => 'nullable|array',
                        ]);

                        $result = $authorizer->executeSuperAdminAuthorized($user->id, fn () => $releaseService->createRelease(
                            $validated['version'],
                            $validated['channel'],
                            $validated['release_notes'],
                            $validated['compatibility'] ?? []
                        ));

                        return response()->json(['release' => $result], 201);
                    })->name('control-center.super-admin.releases.create');

                    Route::post('/extensions/{extension}/approve', function (OfficialExtension $extension, Request $request, OfficialExtensionGovernanceServiceInterface $extensionService, ContextualMutationAuthorizerInterface $authorizer) {
                        $user = $request->user();
                        if ($user === null) {
                            throw UnauthorizedContextException::unauthenticated();
                        }

                        $version = (string) $request->input('approved_version', '1.0.0');
                        $result = $authorizer->executeSuperAdminAuthorized($user->id, fn () => $extensionService->approveExtension($extension->id, $version));

                        return response()->json(['extension' => $result]);
                    })->name('control-center.super-admin.extensions.approve');

                    Route::post('/settings', function (Request $request, PlatformSettingsServiceInterface $settingsService, ContextualMutationAuthorizerInterface $authorizer) {
                        $user = $request->user();
                        if ($user === null) {
                            throw UnauthorizedContextException::unauthenticated();
                        }

                        /** @var array{key: string, value: string, is_encrypted?: bool} $validated */
                        $validated = $request->validate([
                            'key' => 'required|string|max:100',
                            'value' => 'required|string',
                            'is_encrypted' => 'boolean',
                        ]);

                        $result = $authorizer->executeSuperAdminAuthorized($user->id, fn () => $settingsService->set(
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
