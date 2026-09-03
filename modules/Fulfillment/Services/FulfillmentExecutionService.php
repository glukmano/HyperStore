<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Services;

use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\TenantSupplierAccess;
use Modules\Fulfillment\Contracts\FulfillmentExecutionServiceInterface;
use Modules\Fulfillment\Enums\FulfillmentMode;
use Modules\Fulfillment\Enums\FulfillmentStatus;
use Modules\Fulfillment\Enums\ShipmentStatus;
use Modules\Fulfillment\Models\OrderFulfillment;
use Modules\Fulfillment\Models\OrderFulfillmentItem;
use Modules\Fulfillment\Models\OrderShipment;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\SellerOrder;

class FulfillmentExecutionService implements FulfillmentExecutionServiceInterface
{
    public function createFulfillments(SellerOrder $sellerOrder, array $fulfillmentGroups): Collection
    {
        return DB::transaction(function () use ($sellerOrder, $fulfillmentGroups): Collection {
            $createdFulfillments = [];

            foreach ($fulfillmentGroups as $index => $group) {
                $mode = FulfillmentMode::from($group['mode']);
                $fNumber = 'FUL-'.$sellerOrder->seller_order_number.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

                // Invariant: Hybrid parent MUST have null supplier and null inventory location
                $supplierId = $mode === FulfillmentMode::HYBRID ? null : ($group['supplier_id'] ?? null);
                $locationId = $mode === FulfillmentMode::HYBRID ? null : ($group['supplier_location_id'] ?? $group['inventory_location_id'] ?? null);

                if ($supplierId !== null) {
                    $this->authorizeSupplierForFulfillment($supplierId, $sellerOrder);
                }

                /** @var OrderFulfillment $fulfillment */
                $fulfillment = OrderFulfillment::create([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $sellerOrder->tenant_id,
                    'seller_order_id' => $sellerOrder->id,
                    'parent_fulfillment_id' => null,
                    'fulfillment_number' => $fNumber,
                    'fulfillment_mode' => $mode->value,
                    'status' => FulfillmentStatus::PENDING->value,
                    'supplier_id' => $supplierId,
                    'supplier_location_id' => $mode === FulfillmentMode::DROPSHIPPING ? $locationId : null,
                    'inventory_location_id' => $mode !== FulfillmentMode::DROPSHIPPING && $mode !== FulfillmentMode::HYBRID ? $locationId : null,
                    'routing_snapshot' => $group['routing_snapshot'] ?? null,
                    'metadata' => $group['metadata'] ?? null,
                ]);

                // Create Parent Fulfillment Items & track parent quantities per order_item_id
                /** @var array<int, BigDecimal> $parentItemQuantities */
                $parentItemQuantities = [];
                foreach ($group['items'] as $itemData) {
                    $orderItemId = (int) $itemData['order_item_id'];
                    $qtyDec = BigDecimal::of((string) $itemData['quantity']);

                    if ($qtyDec->isNegativeOrZero()) {
                        throw new InvalidArgumentException("Item quantity must be positive, got [{$qtyDec}].");
                    }

                    OrderFulfillmentItem::create([
                        'tenant_id' => $sellerOrder->tenant_id,
                        'order_fulfillment_id' => $fulfillment->id,
                        'order_item_id' => $orderItemId,
                        'quantity' => (string) $qtyDec,
                    ]);

                    $parentItemQuantities[$orderItemId] = ($parentItemQuantities[$orderItemId] ?? BigDecimal::zero())->plus($qtyDec);
                }

                // If HYBRID, process and strictly validate child decompositions
                if ($mode === FulfillmentMode::HYBRID) {
                    $childrenData = $group['children'] ?? [];
                    if (empty($childrenData)) {
                        throw new InvalidArgumentException(
                            "Hybrid fulfillment [{$fNumber}] must define children decompositions."
                        );
                    }

                    /** @var array<int, BigDecimal> $childSumQuantities */
                    $childSumQuantities = [];

                    foreach ($childrenData as $childIndex => $childGroup) {
                        $childMode = FulfillmentMode::from($childGroup['mode']);
                        if ($childMode === FulfillmentMode::HYBRID) {
                            throw new DomainException('Hybrid fulfillment child cannot be hybrid mode.');
                        }

                        $childNumber = $fNumber.'-C'.($childIndex + 1);
                        $childSupplierId = $childGroup['supplier_id'] ?? null;

                        if ($childSupplierId !== null) {
                            $this->authorizeSupplierForFulfillment($childSupplierId, $sellerOrder);
                        }

                        /** @var OrderFulfillment $childFulfillment */
                        $childFulfillment = OrderFulfillment::create([
                            'uuid' => (string) Str::uuid(),
                            'tenant_id' => $sellerOrder->tenant_id,
                            'seller_order_id' => $sellerOrder->id,
                            'parent_fulfillment_id' => $fulfillment->id,
                            'fulfillment_number' => $childNumber,
                            'fulfillment_mode' => $childMode->value,
                            'status' => FulfillmentStatus::PENDING->value,
                            'supplier_id' => $childSupplierId,
                            'supplier_location_id' => $childMode === FulfillmentMode::DROPSHIPPING ? ($childGroup['supplier_location_id'] ?? null) : null,
                            'inventory_location_id' => $childMode !== FulfillmentMode::DROPSHIPPING ? ($childGroup['inventory_location_id'] ?? null) : null,
                            'routing_snapshot' => $childGroup['routing_snapshot'] ?? null,
                            'metadata' => $childGroup['metadata'] ?? null,
                        ]);

                        foreach ($childGroup['items'] as $cItem) {
                            $cItemId = (int) $cItem['order_item_id'];
                            $cQtyDec = BigDecimal::of((string) $cItem['quantity']);

                            if (! isset($parentItemQuantities[$cItemId])) {
                                throw new InvalidArgumentException(
                                    "Child fulfillment item OrderItem [{$cItemId}] is not present on parent hybrid fulfillment."
                                );
                            }

                            if ($cQtyDec->isNegativeOrZero()) {
                                throw new InvalidArgumentException("Child item quantity must be positive, got [{$cQtyDec}].");
                            }

                            OrderFulfillmentItem::create([
                                'tenant_id' => $sellerOrder->tenant_id,
                                'order_fulfillment_id' => $childFulfillment->id,
                                'order_item_id' => $cItemId,
                                'quantity' => (string) $cQtyDec,
                            ]);

                            $childSumQuantities[$cItemId] = ($childSumQuantities[$cItemId] ?? BigDecimal::zero())->plus($cQtyDec);
                        }
                    }

                    // Hybrid Quantity Conservation Validation
                    foreach ($parentItemQuantities as $orderItemId => $parentQty) {
                        $childQty = $childSumQuantities[$orderItemId] ?? BigDecimal::zero();
                        $cmp = $childQty->compareTo($parentQty);

                        if ($cmp < 0) {
                            throw new DomainException(
                                "Hybrid child fulfillments under-allocate OrderItem [{$orderItemId}]: parent requires [{$parentQty}], children sum to [{$childQty}]."
                            );
                        }

                        if ($cmp > 0) {
                            throw new DomainException(
                                "Hybrid child fulfillments over-allocate OrderItem [{$orderItemId}]: parent requires [{$parentQty}], children sum to [{$childQty}]."
                            );
                        }
                    }
                }

                $createdFulfillments[] = $fulfillment->load(['items', 'children.items']);
            }

            return new Collection($createdFulfillments);
        });
    }

    /**
     * Fail closed authorization check mirroring DropshipOrderOrchestrator's scope
     * enforcement, applied at fulfillment-creation time (not only at PurchaseOrder
     * time). Locks the Supplier row (and, for platform suppliers, the
     * TenantSupplierAccess row) so this is serialized against a concurrent
     * deactivation / access revocation.
     */
    private function authorizeSupplierForFulfillment(int $supplierId, SellerOrder $sellerOrder): void
    {
        /** @var Supplier|null $supplier */
        $supplier = Supplier::query()
            ->where('id', $supplierId)
            ->lockForUpdate()
            ->first();

        if ($supplier === null) {
            throw new DomainException("Supplier [{$supplierId}] does not exist.");
        }

        if (! $supplier->is_active) {
            throw new DomainException("Supplier [{$supplierId}] has been deactivated.");
        }

        if ($supplier->isPlatform()) {
            $access = TenantSupplierAccess::query()
                ->where('tenant_id', $sellerOrder->tenant_id)
                ->where('supplier_id', $supplier->id)
                ->where('is_enabled', true)
                ->lockForUpdate()
                ->first();

            if ($access === null) {
                throw new DomainException(
                    "Platform supplier [{$supplier->id}] is not enabled for tenant [{$sellerOrder->tenant_id}]."
                );
            }
        } elseif ($supplier->isTenant()) {
            if ($supplier->tenant_id !== $sellerOrder->tenant_id) {
                throw new DomainException(
                    "Fulfillment tenant [{$sellerOrder->tenant_id}] does not match Tenant supplier [{$supplier->id}] tenant [{$supplier->tenant_id}]."
                );
            }
        } elseif ($supplier->isPrivateVendor()) {
            if ($supplier->tenant_id !== $sellerOrder->tenant_id) {
                throw new DomainException(
                    "Fulfillment tenant [{$sellerOrder->tenant_id}] does not match Private Vendor supplier [{$supplier->id}] tenant [{$supplier->tenant_id}]."
                );
            }

            if ($sellerOrder->vendor_id === null || $supplier->vendor_id !== $sellerOrder->vendor_id) {
                throw new DomainException(
                    "Vendor isolation violation: SellerOrder vendor [{$sellerOrder->vendor_id}] cannot use Supplier belonging to vendor [{$supplier->vendor_id}]."
                );
            }
        }
    }

    public function shipFulfillment(
        OrderFulfillment $fulfillment,
        string $carrierCode,
        string $trackingNumber,
        ?string $trackingUrl = null
    ): OrderShipment {
        return DB::transaction(function () use (
            $fulfillment,
            $carrierCode,
            $trackingNumber,
            $trackingUrl
        ): OrderShipment {
            /** @var OrderFulfillment $locked */
            $locked = OrderFulfillment::query()
                ->where('id', $fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Hybrid parent guard
            if ($locked->isHybrid()) {
                throw new DomainException(
                    'Cannot ship a hybrid fulfillment directly. Disassemble and ship atomic child fulfillments.'
                );
            }

            // 1b. Master Order cancellation guard: lock the same Order row that
            // OrderCancellationService locks, so a concurrent cancellation and a
            // concurrent dispatch are serialized against each other and dispatch
            // fails closed once cancellation has committed.
            $sellerOrder = SellerOrder::query()
                ->where('id', $locked->seller_order_id)
                ->firstOrFail();

            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->where('id', $sellerOrder->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->order_status === OrderStatus::CANCELLED->value) {
                throw new DomainException(
                    "Cannot ship fulfillment [{$locked->id}]: master Order [{$lockedOrder->id}] has been cancelled."
                );
            }

            // 2. Dispatch state transitions check
            if ($locked->status === FulfillmentStatus::CANCELLED->value) {
                throw new DomainException("Cannot ship cancelled fulfillment [{$locked->id}].");
            }

            if ($locked->status === FulfillmentStatus::SHIPPED->value) {
                throw new DomainException("Fulfillment [{$locked->id}] is already shipped.");
            }

            if ($locked->status === FulfillmentStatus::DELIVERED->value) {
                throw new DomainException("Fulfillment [{$locked->id}] is already delivered.");
            }

            if (! in_array($locked->status, [FulfillmentStatus::PENDING->value, FulfillmentStatus::ALLOCATED->value, FulfillmentStatus::PICKING->value, FulfillmentStatus::PACKING->value], true)) {
                throw new DomainException("Fulfillment [{$locked->id}] is not in a shippable state (status: [{$locked->status}]).");
            }

            // Invariant: no duplicate shipment unless multi-shipment modeled
            if ($locked->shipments()->count() > 0) {
                throw new DomainException("Fulfillment [{$locked->id}] already has a shipment registered.");
            }

            $shipmentNumber = 'SHP-'.$locked->fulfillment_number;
            $dispatchTimestamp = now();

            /** @var OrderShipment $shipment */
            $shipment = OrderShipment::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $locked->tenant_id,
                'order_fulfillment_id' => $locked->id,
                'shipment_number' => $shipmentNumber,
                'carrier_code' => $carrierCode,
                'carrier_name' => $carrierCode,
                'tracking_number' => $trackingNumber,
                'tracking_url' => $trackingUrl,
                'status' => ShipmentStatus::IN_TRANSIT->value,
                'dispatched_at' => $dispatchTimestamp,
            ]);

            $locked->status = FulfillmentStatus::SHIPPED->value;
            $locked->shipped_at = $dispatchTimestamp;

            $locked->save();

            return $shipment;
        });
    }
}
