<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Affiliate\Http\Controllers\AffiliateClickTrackingController;
use Modules\Affiliate\Livewire\ControlCenter\AffiliateCampaignManager;
use Modules\Affiliate\Livewire\ControlCenter\AffiliateCommissionRuleManager;
use Modules\Affiliate\Livewire\ControlCenter\AffiliateManager;
use Modules\Affiliate\Livewire\ControlCenter\AffiliatePayoutManager;
use Modules\Affiliate\Livewire\Storefront\AffiliateApplicationForm;
use Modules\Affiliate\Livewire\Storefront\AffiliateDashboard;

Route::middleware([ResolveContextMiddleware::class])
    ->get('/r/{code}', [AffiliateClickTrackingController::class, 'track'])
    ->name('affiliate.click-track');

Route::prefix('control-center/affiliate')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.affiliate.')
    ->group(function (): void {
        Route::get('/affiliates', AffiliateManager::class)->name('affiliates');
        Route::get('/campaigns', AffiliateCampaignManager::class)->name('campaigns');
        Route::get('/commission-rules', AffiliateCommissionRuleManager::class)->name('commission-rules');
        Route::get('/payouts', AffiliatePayoutManager::class)->name('payouts');
    });

Route::prefix('account/affiliate')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('account.affiliate.')
    ->group(function (): void {
        Route::get('/apply', AffiliateApplicationForm::class)->name('apply');
        Route::get('/dashboard', AffiliateDashboard::class)->name('dashboard');
    });
