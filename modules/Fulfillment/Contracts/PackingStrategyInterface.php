<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Contracts;

use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Shipping\ValueObjects\PackageCandidate;

interface PackingStrategyInterface
{
    /**
     * @param  array<int, FulfillmentItemLine>  $items
     * @return array<int, PackageCandidate>
     */
    public function pack(array $items, ?int $inventorySourceId = null): array;
}
