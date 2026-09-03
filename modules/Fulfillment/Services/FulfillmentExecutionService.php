<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Services;

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
use Modules\Order\Models\SellerOrder;

class FulfillmentExecutionService implements FulfillmentExecutionServiceInterface
{
    public function createFulfillments(SellerOrder $sellerOrder, array $groups): Collection
    {
        if (empty($groups)) {
            throw new InvalidArgumentException('Fulfillment groups cannot be empty.');
        }

        return DB::transaction(function () use ($sellerOrder, $groups): Collection {
            $createdFulfillments = new Collection;
            $counter = 1;

            foreach ($groups as $group) {
                $modeStr = (string) $group['mode'];
                $mode = FulfillmentMode::from($modeStr);

                $fulfillmentNumber = $sellerOrder->seller_order_number.'-F'.$counter++;

                if ($mode === FulfillmentMode::HYBRID) {
                    // Parent hybrid fulfillment
                    /** @var OrderFulfillment $parentFulfillment */
                    $parentFulfillment = OrderFulfillment::create([
                        'uuid' => (string) Str::uuid(),
                        'tenant_id' => $sellerOrder->tenant_id,
                        'seller_order_id' => $sellerOrder->id,
                        'parent_fulfillment_id' => null,
                        'fulfillment_number' => $fulfillmentNumber,
                        'fulfillment_mode' => $mode->value,
                        'inventory_source_id' => null,
                        'warehouse_id' => null,
                        'supplier_id' => null,
                        'supplier_location_id' => null,
                        'status' => FulfillmentStatus::PENDING->value,
                    ]);

                    // Attach parent items
                    foreach ($group['items'] as $item) {
                        OrderFulfillmentItem::create([
                            'tenant_id' => $sellerOrder->tenant_id,
                            'order_fulfillment_id' => $parentFulfillment->id,
                            'order_item_id' => $item['order_item_id'],
                            'quantity' => $item['quantity'],
                        ]);
                    }

                    // Create children if provided
                    $children = $group['children'] ?? [];
                    $childCounter = 1;
                    foreach ($children as $child) {
                        $childMode = FulfillmentMode::from((string) $child['mode']);
                        if ($childMode === FulfillmentMode::HYBRID) {
                            throw new InvalidArgumentException('A child fulfillment cannot be hybrid.');
                        }

                        $this->validateSupplierForGroup($sellerOrder, $child);

                        $childNumber = $fulfillmentNumber.'-C'.$childCounter++;
                        /** @var OrderFulfillment $childFulfillment */
                        $childFulfillment = OrderFulfillment::create([
                            'uuid' => (string) Str::uuid(),
                            'tenant_id' => $sellerOrder->tenant_id,
                            'seller_order_id' => $sellerOrder->id,
                            'parent_fulfillment_id' => $parentFulfillment->id,
                            'fulfillment_number' => $childNumber,
                            'fulfillment_mode' => $childMode->value,
                            'inventory_source_id' => $child['inventory_source_id'] ?? null,
                            'warehouse_id' => $child['warehouse_id'] ?? null,
                            'supplier_id' => $child['supplier_id'] ?? null,
                            'supplier_location_id' => $child['supplier_location_id'] ?? null,
                            'status' => FulfillmentStatus::PENDING->value,
                        ]);

                        foreach ($child['items'] as $cItem) {
                            OrderFulfillmentItem::create([
                                'tenant_id' => $sellerOrder->tenant_id,
                                'order_fulfillment_id' => $childFulfillment->id,
                                'order_item_id' => $cItem['order_item_id'],
                                'quantity' => $cItem['quantity'],
                            ]);
                        }
                    }

                    $createdFulfillments->push($parentFulfillment->load(['children.items', 'items']));
                } else {
                    // Atomic leaf fulfillment
                    $this->validateSupplierForGroup($sellerOrder, $group);

                    /** @var OrderFulfillment $fulfillment */
                    $fulfillment = OrderFulfillment::create([
                        'uuid' => (string) Str::uuid(),
                        'tenant_id' => $sellerOrder->tenant_id,
                        'seller_order_id' => $sellerOrder->id,
                        'parent_fulfillment_id' => null,
                        'fulfillment_number' => $fulfillmentNumber,
                        'fulfillment_mode' => $mode->value,
                        'inventory_source_id' => $group['inventory_source_id'] ?? null,
                        'warehouse_id' => $group['warehouse_id'] ?? null,
                        'supplier_id' => $group['supplier_id'] ?? null,
                        'supplier_location_id' => $group['supplier_location_id'] ?? null,
                        'status' => FulfillmentStatus::PENDING->value,
                    ]);

                    foreach ($group['items'] as $item) {
                        OrderFulfillmentItem::create([
                            'tenant_id' => $sellerOrder->tenant_id,
                            'order_fulfillment_id' => $fulfillment->id,
                            'order_item_id' => $item['order_item_id'],
                            'quantity' => $item['quantity'],
                        ]);
                    }

                    $createdFulfillments->push($fulfillment->load('items'));
                }
            }

            return $createdFulfillments;
        });
    }

    public function shipFulfillment(
        OrderFulfillment $fulfillment,
        string $carrierCode,
        string $trackingNumber,
        ?string $trackingUrl = null
    ): OrderShipment {
        return DB::transaction(function () use ($fulfillment, $carrierCode, $trackingNumber, $trackingUrl): OrderShipment {
            /** @var OrderFulfillment $locked */
            $locked = OrderFulfillment::query()
                ->where('tenant_id', $fulfillment->tenant_id)
                ->where('id', $fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var OrderShipment $shipment */
            $shipment = OrderShipment::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $locked->tenant_id,
                'order_fulfillment_id' => $locked->id,
                'carrier_code' => $carrierCode,
                'carrier_name' => $carrierCode,
                'tracking_number' => $trackingNumber,
                'tracking_url' => $trackingUrl,
                'status' => ShipmentStatus::MANIFESTED->value,
                'dispatched_at' => now(),
            ]);

            $locked->update([
                'status' => FulfillmentStatus::SHIPPED->value,
                'shipped_at' => now(),
            ]);

            return $shipment;
        });
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function validateSupplierForGroup(SellerOrder $sellerOrder, array $group): void
    {
        $supplierId = isset($group['supplier_id']) ? (int) $group['supplier_id'] : null;
        if ($supplierId === null) {
            return;
        }

        /** @var Supplier $supplier */
        $supplier = Supplier::query()->where('id', $supplierId)->lockForUpdate()->firstOrFail();

        if (! $supplier->is_active) {
            throw new DomainException("Supplier [{$supplierId}] is deactivated.");
        }

        if ($supplier->isPlatform()) {
            $access = TenantSupplierAccess::query()
                ->where('tenant_id', $sellerOrder->tenant_id)
                ->where('supplier_id', $supplier->id)
                ->lockForUpdate()
                ->first();

            if ($access === null || ! $access->is_enabled) {
                throw new DomainException("Platform supplier [{$supplierId}] is not enabled for tenant [{$sellerOrder->tenant_id}].");
            }
        } elseif ($supplier->isTenant()) {
            if ($supplier->tenant_id !== $sellerOrder->tenant_id) {
                throw new DomainException("Tenant supplier [{$supplierId}] does not belong to tenant [{$sellerOrder->tenant_id}].");
            }
        } elseif ($supplier->isPrivateVendor()) {
            if ($supplier->tenant_id !== $sellerOrder->tenant_id) {
                throw new DomainException("Private vendor supplier [{$supplierId}] does not belong to tenant [{$sellerOrder->tenant_id}].");
            }
            if ($sellerOrder->vendor_id === null || $supplier->vendor_id !== $sellerOrder->vendor_id) {
                throw new DomainException("Private vendor supplier [{$supplierId}] vendor does not match seller order vendor.");
            }
        }
    }
}
