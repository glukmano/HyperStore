<?php

declare(strict_types=1);

namespace Modules\Fulfillment\DTOs;

final readonly class FulfillmentPlan
{
    /**
     * @param  array<int, FulfillmentGroup>  $groups
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $tenantId,
        public array $groups,
        public bool $hasSplits = false,
        public array $metadata = []
    ) {}
}
