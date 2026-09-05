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
