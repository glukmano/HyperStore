<?php

declare(strict_types=1);

namespace Modules\Reviews\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Reviews\Events\ProductReviewApproved;
use Modules\Reviews\Events\ProductReviewRetracted;
use Modules\Reviews\Services\RatingAggregateService;

final class RecomputeProductRatingAggregate implements ShouldQueue
{
    public function __construct(
        private readonly RatingAggregateService $ratingAggregateService,
    ) {}

    public function handle(ProductReviewApproved|ProductReviewRetracted $event): void
    {
        $this->ratingAggregateService->recomputeForProduct($event->tenantId, $event->productId);
    }
}
