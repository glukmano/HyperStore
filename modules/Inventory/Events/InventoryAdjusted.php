<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockItem;

class InventoryAdjusted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly StockItem $stockItem,
        public readonly InventoryMovement $movement
    ) {}
}
