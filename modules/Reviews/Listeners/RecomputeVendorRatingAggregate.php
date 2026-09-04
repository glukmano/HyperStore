<?php

declare(strict_types=1);

namespace Modules\Reviews\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Reviews\Events\VendorReviewApproved;
use Modules\Reviews\Events\VendorReviewRetracted;
use Modules\Reviews\Services\RatingAggregateService;

final class RecomputeVendorRatingAggregate implements ShouldQueue
{
    public function __construct(
        private readonly RatingAggregateService $ratingAggregateService,
    ) {}

    public function handle(VendorReviewApproved|VendorReviewRetracted $event): void
    {
        $this->ratingAggregateService->recomputeForVendor($event->tenantId, $event->vendorId);
    }
}
