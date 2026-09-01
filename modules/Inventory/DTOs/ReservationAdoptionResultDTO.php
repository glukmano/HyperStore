<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Models\InventoryReservation;

final readonly class ReservationAdoptionResultDTO
{
    public function __construct(
        public bool $isSuccess,
        public ?InventoryReservation $reservation,
        public string $message = '',
        public bool $wasAlreadyAdopted = false,
    ) {}
}
