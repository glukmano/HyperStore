<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Ledger\Http\Controllers\AdminJournalEntryController;
use Modules\Ledger\Http\Controllers\AdminLedgerAccountController;

Route::prefix('api/v1/admin/ledger')->middleware(['auth:sanctum'])->group(function (): void {
    Route::get('/accounts', [AdminLedgerAccountController::class, 'index']);
    Route::get('/accounts/{uuid}', [AdminLedgerAccountController::class, 'show']);

    Route::get('/journals', [AdminJournalEntryController::class, 'index']);
    Route::get('/journals/{uuid}', [AdminJournalEntryController::class, 'show']);
    Route::post('/journals/{uuid}/reverse', [AdminJournalEntryController::class, 'reverse']);
});
