<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Marketplace\Http\Controllers\AdminMarketplaceVendorController;
use Modules\Marketplace\Http\Controllers\StorefrontVendorController;
use Modules\Marketplace\Http\Controllers\VendorPortalController;

Route::prefix('api/v1')->group(function (): void {
    // Public Storefront Resolution
    Route::prefix('storefront/vendors')->group(function (): void {
        Route::get('by-path/{slug}', [StorefrontVendorController::class, 'showByPath']);
        Route::get('by-subdomain/{slug}', [StorefrontVendorController::class, 'showBySubdomain']);
        Route::get('by-custom-domain', [StorefrontVendorController::class, 'showByCustomDomain']);
    });

    // Vendor Portal Operations
    Route::prefix('vendor-portal')->group(function (): void {
        Route::post('register', [VendorPortalController::class, 'register']);
        Route::post('invitations/accept', [VendorPortalController::class, 'acceptInvite']);
        Route::get('balances', [VendorPortalController::class, 'balances']);
        Route::post('payouts/request', [VendorPortalController::class, 'requestPayout']);
        Route::post('invitations', [VendorPortalController::class, 'inviteStaff']);
        Route::post('ownership/transfer', [VendorPortalController::class, 'transferOwnership']);
    });

    // Admin Marketplace Operations
    Route::prefix('admin/marketplace')->group(function (): void {
        Route::get('vendors', [AdminMarketplaceVendorController::class, 'index']);
        Route::get('vendors/{uuid}', [AdminMarketplaceVendorController::class, 'show']);
        Route::post('vendors/{uuid}/approve', [AdminMarketplaceVendorController::class, 'approve']);
        Route::post('vendors/{uuid}/suspend', [AdminMarketplaceVendorController::class, 'suspend']);
        Route::post('vendors/{uuid}/verify', [AdminMarketplaceVendorController::class, 'verify']);

        Route::get('payouts', [AdminMarketplaceVendorController::class, 'payoutsIndex']);
        Route::post('payouts/{id}/approve', [AdminMarketplaceVendorController::class, 'approvePayout']);
        Route::post('payouts/{id}/finalize', [AdminMarketplaceVendorController::class, 'finalizePayout']);
    });
});
