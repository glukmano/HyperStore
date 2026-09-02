<?php

declare(strict_types=1);

namespace Modules\Order\DTOs;

use Modules\Order\Enums\OrderActorType;

final readonly class OrderCreationDTO
{
    public function __construct(
        public int $tenantId,
        public int $checkoutId,
        public ?string $idempotencyKey = null,
        public OrderActorType $actorType = OrderActorType::CUSTOMER,
        public ?int $actorId = null
    ) {}
}
