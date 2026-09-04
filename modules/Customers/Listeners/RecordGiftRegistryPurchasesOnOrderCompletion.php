<?php

declare(strict_types=1);

namespace Modules\Customers\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Customers\Services\GiftRegistryService;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderStatusChanged;

final class RecordGiftRegistryPurchasesOnOrderCompletion implements ShouldQueue
{
    public function __construct(
        private readonly GiftRegistryService $giftRegistryService,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->dimension !== 'order_status' || $event->toStatus !== OrderStatus::COMPLETED->value) {
            return;
        }

        foreach ($event->order->items as $orderItem) {
            $registryItem = $this->giftRegistryService->findRegistryItemForOrderItem($orderItem);

            if ($registryItem === null) {
                continue;
            }

            $this->giftRegistryService->recordPurchase(
                item: $registryItem,
                orderId: $event->order->id,
                orderItemId: $orderItem->id,
                purchaserUserId: $event->order->user_id,
                quantity: (int) $orderItem->quantity,
            );
        }
    }
}
