<?php

declare(strict_types=1);

namespace Modules\Order\DTOs;

use Modules\Order\Models\Order;

final readonly class OrderCreationResultDTO
{
    public function __construct(
        public Order $order,
        public bool $isReplay = false,
        public ?string $guestAccessToken = null
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'order_id' => $this->order->id,
            'uuid' => $this->order->uuid,
            'order_number' => $this->order->order_number,
            'order_status' => $this->order->order_status,
            'payment_status' => $this->order->payment_status,
            'fulfillment_status' => $this->order->fulfillment_status,
            'currency' => $this->order->currency,
            'grand_total_minor' => $this->order->grand_total_minor,
            'is_replay' => $this->isReplay,
        ];

        if ($this->guestAccessToken !== null) {
            $data['guest_access_token'] = $this->guestAccessToken;
        }

        return $data;
    }
}
