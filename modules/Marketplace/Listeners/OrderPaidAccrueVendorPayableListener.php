<?php

declare(strict_types=1);

namespace Modules\Marketplace\Listeners;

use Illuminate\Support\Facades\Log;
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
            // Non-marketplace/system-owned item
            if ($item->vendor_id === null) {
                continue;
            }

            // Guard: Snapshot fields must be present. Fail closed if missing for vendor-attributed item.
            if (
                $item->commission_basis_minor === null ||
                $item->commission_amount_minor === null ||
                $item->commission_currency === null
            ) {
                Log::error("Order item {$item->id} for order {$order->uuid} is vendor-attributed but missing frozen commission snapshots.");
                throw new \RuntimeException("Order item {$item->id} missing required frozen commission snapshot fields.");
            }

            $amountMinor = (int) $item->commission_basis_minor;
            $commissionMinor = (int) $item->commission_amount_minor;
            $currency = (string) $item->commission_currency;
            $sourceUuid = (string) ($item->uuid ?? $order->uuid.':item:'.$item->id);

            $this->subledgerService->accrueEarning(
                tenantId: $order->tenant_id,
                vendorId: (int) $item->vendor_id,
                orderItemId: $item->id,
                sourceType: 'order_item',
                sourceUuid: $sourceUuid,
                currency: $currency,
                amountMinor: $amountMinor,
                commissionMinor: $commissionMinor,
                storeId: $order->store_id
            );
        }
    }
}
