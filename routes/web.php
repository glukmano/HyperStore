<?php

declare(strict_types=1);

use App\Core\Localization\Middleware\SetLocaleAndDirectionMiddleware;
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
use App\Livewire\ControlCenter\DashboardOverview;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Health check (Minimal Public Liveness) ────────────────────────────────────
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
        // Authenticated Context-Governed Routes
        Route::middleware([ControlCenterContextMiddleware::class])->group(function () {
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
