<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Contracts;

use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\PackingResult;
use Modules\Shipping\ValueObjects\PackageCandidate;

interface PackingStrategyInterface
{
    /**
     * @param  array<int, FulfillmentItemLine>  $items
     * @return array<int, PackageCandidate>|PackingResult
     */
    public function pack(array $items, ?int $inventorySourceId = null): array|PackingResult;
}
