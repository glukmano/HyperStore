<?php

declare(strict_types=1);

use App\Core\Localization\Middleware\SetLocaleAndDirectionMiddleware;
use App\Core\SuperAdmin\Contracts\PlatformHealthServiceInterface;
use App\Core\SuperAdmin\Http\Middleware\ControlCenterContextMiddleware;
use App\Core\SuperAdmin\Http\Middleware\EnsureSuperAdminMiddleware;
use App\Livewire\ControlCenter\DashboardOverview;
use Illuminate\Support\Facades\Route;

// ── Health check ──────────────────────────────────────────────────────────────
Route::get('/up', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'version' => app()->version(),
        'php' => PHP_VERSION,
        'time' => now()->toIso8601String(),
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

            // Super Admin Only Area
            Route::middleware([EnsureSuperAdminMiddleware::class])
                ->prefix('super-admin')
                ->group(function () {
                    Route::get('/dashboard', DashboardOverview::class)->name('control-center.super-admin.dashboard');
                });
        });
    });
