<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\AvailabilityResultDTO;
use Modules\Inventory\DTOs\InventoryContext;

interface InventoryAvailabilityServiceInterface
{
    public function check(int $productId, ?int $variantId, InventoryContext $context): AvailabilityResultDTO;
}
