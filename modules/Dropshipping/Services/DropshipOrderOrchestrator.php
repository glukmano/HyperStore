<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Dropshipping\Contracts\DropshipOrderOrchestratorInterface;
use Modules\Dropshipping\Contracts\ExternalSupplierPortInterface;
use Modules\Dropshipping\Enums\PurchaseOrderStatus;
use Modules\Dropshipping\Exceptions\MissingSupplierProcurementContractException;
use Modules\Dropshipping\Models\PurchaseOrder;
use Modules\Dropshipping\Models\PurchaseOrderLine;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierOffer;
use Modules\Fulfillment\Models\OrderFulfillment;

class DropshipOrderOrchestrator implements DropshipOrderOrchestratorInterface
{
    public function __construct(
        private readonly ?ExternalSupplierPortInterface $externalSupplierPort = null
    ) {}

    public function createPurchaseOrderForFulfillment(OrderFulfillment $fulfillment): PurchaseOrder
    {
        return DB::transaction(function () use ($fulfillment): PurchaseOrder {
            // 0. Hybrid parent guard
            if ($fulfillment->isHybrid()) {
                throw new DomainException(
                    "Cannot create a purchase order for a hybrid fulfillment parent [{$fulfillment->id}]. Disassemble to atomic children."
                );
            }

            // 1. Pessimistic lock on OrderFulfillment to serialize PO creation
            /** @var OrderFulfillment $lockedFulfillment */
            $lockedFulfillment = OrderFulfillment::query()
                ->where('tenant_id', $fulfillment->tenant_id)
                ->where('id', $fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency: return existing PO if already materialized
            if ($lockedFulfillment->purchaseOrder !== null) {
                return $lockedFulfillment->purchaseOrder;
            }

            if ($fulfillment->supplier_id === null) {
                throw new DomainException("Fulfillment [{$fulfillment->id}] does not have an assigned supplier.");
            }

            // 2. Lock Supplier record
            /** @var Supplier $supplier */
            $supplier = Supplier::query()
                ->where('id', $fulfillment->supplier_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $supplier->is_active) {
                throw new DomainException("Supplier [{$supplier->id}] has been deactivated.");
            }

            // 3. Verify supplier tenancy & scope boundaries
            if ($supplier->isPlatform()) {
                $access = $supplier->tenantAccesses()
                    ->where('tenant_id', $fulfillment->tenant_id)
                    ->where('is_enabled', true)
                    ->lockForUpdate()
                    ->first();

                if ($access === null) {
                    throw new DomainException(
                        "Platform supplier [{$supplier->id}] is not enabled for tenant [{$fulfillment->tenant_id}]."
                    );
                }
            } elseif ($supplier->isTenant()) {
                if ($supplier->tenant_id !== $fulfillment->tenant_id) {
                    throw new DomainException(
                        "Tenant supplier [{$supplier->id}] does not belong to tenant [{$fulfillment->tenant_id}]."
                    );
                }
            } elseif ($supplier->isPrivateVendor()) {
                if ($supplier->tenant_id !== $fulfillment->tenant_id) {
                    throw new DomainException(
                        "Private vendor supplier [{$supplier->id}] does not belong to tenant [{$fulfillment->tenant_id}]."
                    );
                }
                $sellerOrder = $fulfillment->sellerOrder;
                if ($sellerOrder->vendor_id === null || $supplier->vendor_id !== $sellerOrder->vendor_id) {
                    throw new DomainException(
                        "Private vendor supplier [{$supplier->id}] vendor [{$supplier->vendor_id}] does not match seller order vendor [{$sellerOrder->vendor_id}]."
                    );
                }
            }

            // 4. Build Purchase Order Header
            $poNumber = 'PO-'.$fulfillment->fulfillment_number;
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $fulfillment->tenant_id,
                'supplier_id' => $supplier->id,
                'order_fulfillment_id' => $fulfillment->id,
                'po_number' => $poNumber,
                'type' => 'dropship',
                'status' => PurchaseOrderStatus::DRAFT->value,
                'currency' => $supplier->currency,
                'subtotal_minor' => 0,
                'tax_minor' => 0,
                'shipping_minor' => 0,
                'total_minor' => 0,
            ]);

            $fulfillment->loadMissing('items.orderItem');
            $totalCostMinor = 0;

            // Check if routing_snapshot has frozen line decisions
            $routingSnapshot = $fulfillment->routing_snapshot;
            $frozenLinesByOrderItemId = [];
            if (is_array($routingSnapshot) && isset($routingSnapshot['items']) && is_array($routingSnapshot['items'])) {
                foreach ($routingSnapshot['items'] as $snapItem) {
                    if (isset($snapItem['order_item_id'])) {
                        $frozenLinesByOrderItemId[(int) $snapItem['order_item_id']] = $snapItem;
                    }
                }
            }

            foreach ($fulfillment->items as $fItem) {
                $orderItem = $fItem->orderItem;
                $orderItemId = (int) $orderItem->id;

                if (isset($frozenLinesByOrderItemId[$orderItemId])) {
                    // Consume frozen routing decision
                    $lineData = $frozenLinesByOrderItemId[$orderItemId];
                    $supplierSku = (string) $lineData['supplier_sku'];
                    $unitCostMinor = (int) $lineData['procurement_cost_minor'];
                } else {
                    // Look up authoritative contract
                    $spv = $supplier->productVariants()
                        ->where('product_id', $orderItem->product_id)
                        ->where('product_variant_id', $orderItem->variant_id)
                        ->first();

                    if ($spv === null) {
                        throw MissingSupplierProcurementContractException::forItem(
                            $orderItem->id,
                            $orderItem->sku_snapshot ?? 'unknown',
                            $supplier->id,
                            'No SupplierProductVariant mapping exists'
                        );
                    }

                    $offerQuery = SupplierOffer::query()
                        ->where('supplier_id', $supplier->id)
                        ->where('supplier_product_variant_id', $spv->id)
                        ->where('is_available', true);

                    if ($fulfillment->supplier_location_id !== null) {
                        $offerQuery->where('supplier_location_id', $fulfillment->supplier_location_id);
                    }

                    /** @var SupplierOffer|null $offer */
                    $offer = $offerQuery->first();

                    if ($offer === null) {
                        throw MissingSupplierProcurementContractException::forItem(
                            $orderItem->id,
                            $orderItem->sku_snapshot ?? 'unknown',
                            $supplier->id,
                            'No active SupplierOffer exists for location'
                        );
                    }

                    $supplierSku = $spv->supplier_sku;
                    $unitCostMinor = $offer->cost_minor;
                }

                // Procurement decimal money math using brick/math
                $qtyDec = BigDecimal::of((string) $fItem->quantity);
                $unitCostDec = BigDecimal::of($unitCostMinor);
                $lineCostMinor = $qtyDec->multipliedBy($unitCostDec)->toScale(0, RoundingMode::HalfUp)->toInt();

                PurchaseOrderLine::create([
                    'tenant_id' => $fulfillment->tenant_id,
                    'purchase_order_id' => $po->id,
                    'product_id' => $orderItem->product_id,
                    'supplier_sku' => $supplierSku,
                    'internal_sku_snapshot' => $orderItem->sku_snapshot,
                    'description' => $orderItem->name_snapshot ?? $supplierSku,
                    'quantity' => $fItem->quantity,
                    'unit_cost_minor' => $unitCostMinor,
                    'total_cost_minor' => $lineCostMinor,
                ]);

                $totalCostMinor += $lineCostMinor;
            }

            $po->update([
                'subtotal_minor' => $totalCostMinor,
                'total_minor' => $totalCostMinor,
            ]);

            return $po->load('lines');
        });
    }

    public function transmitPurchaseOrder(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder): PurchaseOrder {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()
                ->where('tenant_id', $purchaseOrder->tenant_id)
                ->where('id', $purchaseOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($po->status !== PurchaseOrderStatus::DRAFT->value) {
                return $po; // Idempotent no-op
            }

            if ($this->externalSupplierPort !== null) {
                $response = $this->externalSupplierPort->submitPurchaseOrder($po);
                $po->status = PurchaseOrderStatus::SUBMITTED->value;
                $po->submitted_at = now();
                $po->notes = 'Ext Ref: '.($response['external_reference'] ?? 'none');
            } else {
                $po->status = PurchaseOrderStatus::SUBMITTED->value;
                $po->submitted_at = now();
            }

            $po->save();

            return $po;
        });
    }
}
