<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Events;

use Modules\Fulfillment\DTOs\FulfillmentPlan;

final readonly class FulfillmentPlanCreated
{
    public function __construct(public FulfillmentPlan $plan) {}
}
