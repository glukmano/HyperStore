<?php

declare(strict_types=1);

namespace Modules\Order\DTOs;

use Modules\Order\Enums\OrderActorType;
use Modules\Order\Enums\StatusDimension;

final readonly class OrderTransitionDTO
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $fromStatus,
        public string $toStatus,
        public StatusDimension $dimension = StatusDimension::ORDER,
        public ?string $reason = null,
        public OrderActorType $actorType = OrderActorType::SYSTEM,
        public ?int $actorId = null,
        public ?array $metadata = null
    ) {}
}
