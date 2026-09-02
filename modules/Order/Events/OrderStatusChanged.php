<?php

declare(strict_types=1);

namespace Modules\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Order\Models\Order;

class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public string $dimension,
        public string $fromStatus,
        public string $toStatus,
        public ?string $reason = null,
        public ?string $actorType = null,
        public ?int $actorId = null
    ) {}
}
