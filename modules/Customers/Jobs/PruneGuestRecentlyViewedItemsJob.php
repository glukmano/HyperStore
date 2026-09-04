<?php

declare(strict_types=1);

namespace Modules\Customers\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Customers\Models\RecentlyViewedItem;

/**
 * Guest (session-scoped) Recently Viewed rows are not durable identity and
 * must not accumulate forever — purged after 30 days, scheduled daily.
 * Authenticated (user-scoped) rows are never touched by this job; they are
 * bounded by RecentlyViewedService's own per-write retention trim instead.
 */
final class PruneGuestRecentlyViewedItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const int RETENTION_DAYS = 30;

    public function handle(): void
    {
        RecentlyViewedItem::query()
            ->whereNotNull('session_id')
            ->whereNull('user_id')
            ->where('viewed_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();
    }
}
