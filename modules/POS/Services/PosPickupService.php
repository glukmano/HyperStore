<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Order\Enums\FulfillmentStatus;
use Modules\Order\Models\Order;
use Modules\POS\Exceptions\PosPickupException;

/**
 * Owner Delta §7: complete BOPIS lifecycle — reserved/preparing (existing
 * UNFULFILLED/PARTIALLY_FULFILLED) -> READY_FOR_PICKUP -> PICKED_UP, plus
 * deterministic expiry/no-show. Pickup confirmation is idempotent and
 * concurrency-safe: two staff actions racing to mark the same pickup
 * collected result in exactly one transition (row-locked status
 * transition — no second pickup Order engine).
 */
final class PosPickupService
{
    public function __construct(
        private readonly InventoryReservationServiceInterface $inventoryReservationService,
    ) {}

    public function markReadyForPickup(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::query()->where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->fulfillment_status === FulfillmentStatus::READY_FOR_PICKUP->value
                || $locked->fulfillment_status === FulfillmentStatus::PICKED_UP->value) {
                return $locked;
            }

            $locked->fulfillment_status = FulfillmentStatus::READY_FOR_PICKUP->value;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Idempotent + concurrency-safe: only a row locked at status
     * READY_FOR_PICKUP transitions to PICKED_UP. A second concurrent
     * caller sees the already-updated status inside its own lock wait and
     * safely no-ops.
     */
    public function confirmPickedUp(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            /** @var Order $locked */
            $locked = Order::query()->where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->fulfillment_status === FulfillmentStatus::PICKED_UP->value) {
                return false;
            }

            if ($locked->fulfillment_status !== FulfillmentStatus::READY_FOR_PICKUP->value) {
                throw new PosPickupException("Order [{$locked->id}] is not ready for pickup (status: {$locked->fulfillment_status}).");
            }

            $locked->fulfillment_status = FulfillmentStatus::PICKED_UP->value;
            $locked->save();

            return true;
        });
    }

    /**
     * Deterministic no-show handling: releases any still-active Inventory
     * reservation and cancels the pickup fulfillment. A paid Order's
     * refund is handled through the existing Returns/refund flow (never a
     * second, automatic refund engine here) — staff process it manually
     * once notified of the expiry.
     */
    public function expireNoShow(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::query()->where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->fulfillment_status, [FulfillmentStatus::PICKED_UP->value, FulfillmentStatus::CANCELLED->value], true)) {
                return $locked;
            }

            foreach ((array) ($locked->reservation_references ?? []) as $ref) {
                $key = (string) ($ref['reservation_key'] ?? '');
                if ($key !== '') {
                    $this->inventoryReservationService->release($locked->tenant_id, $key);
                }
            }

            $locked->fulfillment_status = FulfillmentStatus::CANCELLED->value;
            $locked->save();

            return $locked;
        });
    }
}
