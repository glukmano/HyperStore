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
use Modules\Dropshipping\Exceptions\MissingFrozenSupplierRoutingDecisionException;
use Modules\Dropshipping\Models\PurchaseOrder;
use Modules\Dropshipping\Models\PurchaseOrderLine;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierProductVariant;
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

            // Automatic dropship procurement is historically reproducible ONLY from a
            // frozen SupplierRoutingEngine decision (SupplierRoutingEngine -> selected
            // SupplierOffer -> OrderFulfillment.routing_snapshot -> PurchaseOrder).
            // A mutable re-lookup of the current cheapest/available SupplierOffer at
            // PO-creation time is explicitly forbidden: it would let a PO silently use
            // a different cost/offer/supplier than what the customer's order was
            // actually routed against. Missing frozen data fails closed.
            $routingSnapshot = $fulfillment->routing_snapshot;

            if (! is_array($routingSnapshot) || ! isset($routingSnapshot['items']) || ! is_array($routingSnapshot['items'])) {
                throw MissingFrozenSupplierRoutingDecisionException::forFulfillment(
                    $fulfillment->id,
                    'routing_snapshot is missing or has no frozen [items] decisions'
                );
            }

            // The frozen decision must still point at the exact Supplier (and
            // Location, when the fulfillment carries one) it was routed against —
            // it must never be silently reinterpreted against a different Supplier
            // or Location the fulfillment happens to carry now.
            if (! isset($routingSnapshot['supplier_id']) || (int) $routingSnapshot['supplier_id'] !== $supplier->id) {
                throw MissingFrozenSupplierRoutingDecisionException::forFulfillment(
                    $fulfillment->id,
                    "frozen routing_snapshot supplier_id does not match Fulfillment Supplier [{$supplier->id}]"
                );
            }

            if ($fulfillment->supplier_location_id !== null
                && (! isset($routingSnapshot['supplier_location_id'])
                    || (int) $routingSnapshot['supplier_location_id'] !== (int) $fulfillment->supplier_location_id)
            ) {
                throw MissingFrozenSupplierRoutingDecisionException::forFulfillment(
                    $fulfillment->id,
                    "frozen routing_snapshot supplier_location_id does not match Fulfillment supplier_location_id [{$fulfillment->supplier_location_id}]"
                );
            }

            $frozenLinesByOrderItemId = [];
            foreach ($routingSnapshot['items'] as $snapItem) {
                if (isset($snapItem['order_item_id'])) {
                    $frozenLinesByOrderItemId[(int) $snapItem['order_item_id']] = $snapItem;
                }
            }

            foreach ($fulfillment->items as $fItem) {
                $orderItem = $fItem->orderItem;
                $orderItemId = (int) $orderItem->id;

                if (! isset($frozenLinesByOrderItemId[$orderItemId])) {
                    throw MissingFrozenSupplierRoutingDecisionException::forItem(
                        $fulfillment->id,
                        $orderItemId,
                        'no frozen routing line exists for this OrderItem'
                    );
                }

                $lineData = $frozenLinesByOrderItemId[$orderItemId];

                foreach (['supplier_product_variant_id', 'supplier_sku', 'procurement_cost_minor', 'procurement_currency'] as $requiredKey) {
                    if (! isset($lineData[$requiredKey])) {
                        throw MissingFrozenSupplierRoutingDecisionException::forItem(
                            $fulfillment->id,
                            $orderItemId,
                            "frozen routing line is missing required key [{$requiredKey}]"
                        );
                    }
                }

                // Validate the frozen mapping identity is still structurally referentially
                // valid (it belongs to this Supplier) — NOT that its current price/stock/
                // priority still matches. Historical reproducibility means the frozen
                // cost/SKU below are authoritative regardless of what the mapping looks
                // like today.
                $spv = SupplierProductVariant::query()
                    ->where('id', (int) $lineData['supplier_product_variant_id'])
                    ->where('supplier_id', $supplier->id)
                    ->first();

                if ($spv === null) {
                    throw MissingFrozenSupplierRoutingDecisionException::forItem(
                        $fulfillment->id,
                        $orderItemId,
                        "frozen supplier_product_variant_id [{$lineData['supplier_product_variant_id']}] no longer exists or no longer belongs to Supplier [{$supplier->id}]"
                    );
                }

                if ((string) $lineData['procurement_currency'] !== $supplier->currency) {
                    throw MissingFrozenSupplierRoutingDecisionException::forItem(
                        $fulfillment->id,
                        $orderItemId,
                        "frozen procurement_currency [{$lineData['procurement_currency']}] does not match Supplier currency [{$supplier->currency}]"
                    );
                }

                $supplierSku = (string) $lineData['supplier_sku'];
                $unitCostMinor = (int) $lineData['procurement_cost_minor'];

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
