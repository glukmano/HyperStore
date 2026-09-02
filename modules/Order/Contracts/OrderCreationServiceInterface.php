<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\DTOs\OrderCreationResultDTO;

interface OrderCreationServiceInterface
{
    /**
     * Creates an Order from a ready CheckoutSession handoff.
     * Atomically adopts required inventory reservations and persists immutable snapshots.
     */
    public function createFromCheckout(OrderCreationDTO $dto): OrderCreationResultDTO;
}
