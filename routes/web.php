<?php

declare(strict_types=1);

use App\Core\Localization\Middleware\SetLocaleAndDirectionMiddleware;
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
        Route::get('/', DashboardOverview::class)->name('control-center.dashboard');
    });
