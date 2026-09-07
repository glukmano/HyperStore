<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Customers\Jobs\PruneGuestRecentlyViewedItemsJob;
use Modules\Reviews\Jobs\RecomputeAllRatingAggregatesJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PruneGuestRecentlyViewedItemsJob)->daily();
Schedule::job(new RecomputeAllRatingAggregatesJob)->daily();
Schedule::command('marketing:send-abandoned-cart-reminders')->hourly();
Schedule::command('auctions:process-lifecycle')->everyMinute();
Schedule::command('bookings:expire-holds')->everyMinute();
Schedule::command('bookings:generate-slots')->daily();
Schedule::command('subscriptions:process-renewals')->hourly();

// Pre-Production Readiness: these three commands existed but were never
// scheduled — stale checkouts/carts/reservations would otherwise
// accumulate indefinitely. Cadence matches the existing everyMinute
// pattern already proven for auction/booking expiry above.
Schedule::command('hyper:checkout:cleanup-expired')->everyMinute();
Schedule::command('hyper:cart:cleanup-expired')->everyMinute();
Schedule::command('inventory:expire-reservations')->everyMinute();
