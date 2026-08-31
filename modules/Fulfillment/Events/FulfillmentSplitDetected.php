<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Events;

use Modules\Fulfillment\DTOs\FulfillmentPlan;

final readonly class FulfillmentSplitDetected
{
    public function __construct(
        public FulfillmentPlan $plan,
        public int $splitGroupsCount
    ) {}
}
