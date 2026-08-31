<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\ValueObjects\Quantity;

final readonly class ReservationResultDTO
{
    /**
     * @param  array<int, array{stock_item_id: int, source_id: int, quantity: Quantity}>  $allocations
     */
    public function __construct(
        public bool $isSuccess,
        public ?InventoryReservation $reservation,
        public Quantity $reservedQuantity,
        public string $message = '',
        public array $allocations = [],
    ) {}
}
