<?php

declare(strict_types=1);

namespace Modules\Reviews\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Reviews\Services\RatingAggregateService;

/**
 * Nightly drift-correction safety net — recomputes every rating aggregate
 * from scratch, cheap insurance against a missed event.
 */
final class RecomputeAllRatingAggregatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RatingAggregateService $ratingAggregateService): void
    {
        $ratingAggregateService->recomputeAll();
    }
}
