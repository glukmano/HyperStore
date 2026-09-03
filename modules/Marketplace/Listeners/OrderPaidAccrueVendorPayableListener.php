<?php

declare(strict_types=1);

namespace Modules\Marketplace\Listeners;

use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Order\Events\OrderStatusChanged;

final class OrderPaidAccrueVendorPayableListener
{
    public function __construct(
        private readonly VendorPayableSubledgerServiceInterface $subledgerService
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->dimension !== 'payment' || $event->toStatus !== 'paid') {
            return;
        }

        $order = $event->order;
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->vendor_id === null) {
                continue;
            }

            $amountMinor = $item->subtotal_minor - ($item->line_discount_minor ?? 0) - ($item->allocated_cart_discount_minor ?? 0);
            $commissionMinor = $item->commission_amount_minor ?? 0;
            $currency = $item->commission_currency ?? $order->currency;

            $this->subledgerService->accrueEarning(
                tenantId: $order->tenant_id,
                vendorId: $item->vendor_id,
                orderItemId: $item->id,
                sourceType: 'order_item',
                sourceUuid: $order->uuid.':'.$item->id,
                currency: $currency,
                amountMinor: $amountMinor,
                commissionMinor: $commissionMinor,
                storeId: $order->store_id
            );
        }
    }
}
