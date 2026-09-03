<?php

declare(strict_types=1);

use App\Core\Localization\Middleware\SetLocaleAndDirectionMiddleware;
use App\Core\Stores\Contracts\StoreCreationServiceInterface;
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
use App\Livewire\ControlCenter\DashboardOverview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Health Check ──────────────────────────────────────────────────────────────
Route::get('/up', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// ── Control Center ────────────────────────────────────────────────────────────
Route::middleware([SetLocaleAndDirectionMiddleware::class])
    ->prefix('control-center')
    ->group(function () {
        // Platform Health Diagnostics (Public or Protected)
        Route::get('/health', function (PlatformHealthServiceInterface $healthService) {
            return response()->json($healthService->checkHealth());
        })->name('control-center.health');

        // Authenticated Context-Governed Routes
        Route::middleware([ControlCenterContextMiddleware::class])->group(function () {
            Route::get('/', DashboardOverview::class)->name('control-center.dashboard');
            Route::get('/{tenant}', DashboardOverview::class)->name('control-center.tenant.dashboard');

            // Tenant Administration Routes (Tenant Admin / Staff)
            Route::post('/{tenant}/stores', function (int $tenantId, Request $request, StoreCreationServiceInterface $storeService) {
                /** @var array<string, mixed> $validated */
                $validated = $request->validate([
                    'name' => 'required|string|max:255',
                    'slug' => 'nullable|string|max:255',
                ]);
                $user = $request->user();
                $store = $storeService->createStore($tenantId, $validated, $user?->id);

                return response()->json(['store' => $store], 201);
            })->name('control-center.tenant.stores.create');

            Route::post('/{tenant}/memberships/{userId}/revoke', function (int $tenantId, int $userId, TenantMembershipServiceInterface $membershipService) {
                $membership = $membershipService->revokeMembership($tenantId, $userId);

                return response()->json(['membership' => $membership]);
            })->name('control-center.tenant.memberships.revoke');

            // Super Admin Only Area
            Route::middleware([EnsureSuperAdminMiddleware::class])
                ->prefix('super-admin')
                ->group(function () {
                    Route::get('/dashboard', DashboardOverview::class)->name('control-center.super-admin.dashboard');

                    // Tenant Lifecycle Operations
                    Route::post('/tenants/{tenant}/suspend', function (int $tenantId, Request $request, TenantLifecycleServiceInterface $lifecycleService) {
                        $reason = (string) $request->input('reason', 'Administrative suspension');
                        $tenant = $lifecycleService->suspend($tenantId, $reason);

                        return response()->json(['tenant' => $tenant]);
                    })->name('control-center.super-admin.tenants.suspend');

                    Route::post('/tenants/{tenant}/activate', function (int $tenantId, TenantLifecycleServiceInterface $lifecycleService) {
                        $tenant = $lifecycleService->activate($tenantId);

                        return response()->json(['tenant' => $tenant]);
                    })->name('control-center.super-admin.tenants.activate');

                    // SaaS Plan & License Operations
                    Route::post('/plans/{plan}/limits', function (int $planId, Request $request, PlatformSaasPlanMutationServiceInterface $planService) {
                        /** @var array<string, int> $limits */
                        $limits = (array) $request->input('limits', []);
                        $plan = $planService->updateHardLimits($planId, $limits);

                        return response()->json(['plan' => $plan]);
                    })->name('control-center.super-admin.plans.limits');

                    Route::post('/licenses/{tenant}/overrides', function (int $tenantId, Request $request, TenantLicenseServiceInterface $licenseService) {
                        /** @var array<string, int> $limits */
                        $limits = (array) $request->input('override_limits', []);
                        /** @var array<string, mixed> $features */
                        $features = (array) $request->input('override_features', []);
                        $license = $licenseService->updateOverrides($tenantId, $limits, $features);

                        return response()->json(['license' => $license]);
                    })->name('control-center.super-admin.licenses.overrides');

                    // Releases, Extensions, Settings
                    Route::post('/releases', function (Request $request, PlatformReleaseServiceInterface $releaseService) {
                        /** @var array{version: string, channel: string, release_notes: string, compatibility?: array<string, mixed>} $validated */
                        $validated = $request->validate([
                            'version' => 'required|string|max:50',
                            'channel' => 'required|string|max:30',
                            'release_notes' => 'required|string',
                            'compatibility' => 'nullable|array',
                        ]);
                        $release = $releaseService->createRelease(
                            $validated['version'],
                            $validated['channel'],
                            $validated['release_notes'],
                            $validated['compatibility'] ?? []
                        );

                        return response()->json(['release' => $release], 201);
                    })->name('control-center.super-admin.releases.create');

                    Route::post('/extensions/{extension}/approve', function (int $extensionId, Request $request, OfficialExtensionGovernanceServiceInterface $extensionService) {
                        $version = (string) $request->input('approved_version', '1.0.0');
                        $extension = $extensionService->approveExtension($extensionId, $version);

                        return response()->json(['extension' => $extension]);
                    })->name('control-center.super-admin.extensions.approve');

                    Route::post('/settings', function (Request $request, PlatformSettingsServiceInterface $settingsService) {
                        /** @var array{key: string, value: string, is_encrypted?: bool} $validated */
                        $validated = $request->validate([
                            'key' => 'required|string|max:100',
                            'value' => 'required|string',
                            'is_encrypted' => 'boolean',
                        ]);
                        $user = $request->user();
                        $setting = $settingsService->set(
                            $validated['key'],
                            $validated['value'],
                            (bool) ($validated['is_encrypted'] ?? false),
                            $user?->id
                        );

                        return response()->json(['setting' => $setting]);
                    })->name('control-center.super-admin.settings.set');
                });
        });
    });
