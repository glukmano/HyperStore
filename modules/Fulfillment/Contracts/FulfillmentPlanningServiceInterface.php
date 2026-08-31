<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Contracts;

use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\FulfillmentPlan;
use Modules\Shipping\ValueObjects\ShippingContext;

interface FulfillmentPlanningServiceInterface
{
    /**
     * @param  array<int, FulfillmentItemLine>  $items
     */
    public function plan(int $tenantId, array $items, ShippingContext $context): FulfillmentPlan;
}
