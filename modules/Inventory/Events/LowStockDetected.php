<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

class LowStockDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly StockItem $stockItem,
        public readonly Quantity $currentAvailable
    ) {}
}
