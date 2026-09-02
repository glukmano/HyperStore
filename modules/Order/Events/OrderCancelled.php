<?php

declare(strict_types=1);

namespace Modules\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Order\Models\Order;

class OrderCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?string $reason = null
    ) {}
}
