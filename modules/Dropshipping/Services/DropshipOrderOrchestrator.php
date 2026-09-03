<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Services;

use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Dropshipping\Contracts\DropshipOrderOrchestratorInterface;
use Modules\Dropshipping\Enums\PurchaseOrderStatus;
use Modules\Dropshipping\Models\PurchaseOrder;
use Modules\Dropshipping\Models\PurchaseOrderLine;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierOffer;
use Modules\Dropshipping\Models\TenantSupplierAccess;
use Modules\Fulfillment\Models\OrderFulfillment;

class DropshipOrderOrchestrator implements DropshipOrderOrchestratorInterface
{
    public function createPurchaseOrderForFulfillment(OrderFulfillment $fulfillment): PurchaseOrder
    {
        if ($fulfillment->fulfillment_mode !== 'dropshipping' && $fulfillment->fulfillment_mode !== 'print_on_demand') {
            throw new InvalidArgumentException("Fulfillment [{$fulfillment->id}] mode [{$fulfillment->fulfillment_mode}] is not a dropship or POD mode.");
        }

        if ($fulfillment->supplier_id === null) {
            throw new InvalidArgumentException("Fulfillment [{$fulfillment->id}] does not specify a supplier_id.");
        }

        return DB::transaction(function () use ($fulfillment): PurchaseOrder {
            // 1. Idempotent check
            /** @var PurchaseOrder|null $existing */
            $existing = PurchaseOrder::query()
                ->where('tenant_id', $fulfillment->tenant_id)
                ->where('order_fulfillment_id', $fulfillment->id)
                ->with('lines')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // 2. Lock and validate Supplier (Fail Closed on deactivation)
            /** @var Supplier $supplier */
            $supplier = Supplier::query()
                ->where('id', $fulfillment->supplier_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $supplier->is_active) {
                throw new DomainException("Supplier [{$supplier->id}] has been deactivated.");
            }

            // 3. Verify scope invariants under concurrency lock
            if ($supplier->isPlatform()) {
                /** @var TenantSupplierAccess|null $access */
                $access = TenantSupplierAccess::query()
                    ->where('tenant_id', $fulfillment->tenant_id)
                    ->where('supplier_id', $supplier->id)
                    ->lockForUpdate()
                    ->first();

                if ($access === null || ! $access->is_enabled) {
                    throw new DomainException("Platform supplier [{$supplier->id}] is not enabled for tenant [{$fulfillment->tenant_id}].");
                }
            } elseif ($supplier->isTenant()) {
                if ($supplier->tenant_id !== $fulfillment->tenant_id) {
                    throw new DomainException("Tenant supplier [{$supplier->id}] does not belong to tenant [{$fulfillment->tenant_id}].");
                }
            } elseif ($supplier->isPrivateVendor()) {
                if ($supplier->tenant_id !== $fulfillment->tenant_id) {
                    throw new DomainException("Private vendor supplier [{$supplier->id}] does not belong to tenant [{$fulfillment->tenant_id}].");
                }
                $sellerOrder = $fulfillment->sellerOrder;
                if ($sellerOrder->vendor_id === null || $supplier->vendor_id !== $sellerOrder->vendor_id) {
                    throw new DomainException("Private vendor supplier [{$supplier->id}] vendor [{$supplier->vendor_id}] does not match seller order vendor [{$sellerOrder->vendor_id}].");
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

            foreach ($fulfillment->items as $fItem) {
                $orderItem = $fItem->orderItem;

                // Look up matching supplier product variant
                $spv = $supplier->productVariants()
                    ->where('product_id', $orderItem->product_id)
                    ->where('product_variant_id', $orderItem->variant_id)
                    ->first();

                $supplierSku = $spv !== null ? $spv->supplier_sku : $orderItem->sku_snapshot;

                // Cost lookup from offer or default
                $offer = null;
                if ($spv !== null) {
                    $offer = SupplierOffer::query()
                        ->where('supplier_id', $supplier->id)
                        ->where('supplier_product_variant_id', $spv->id)
                        ->where('is_available', true)
                        ->first();
                }

                $unitCostMinor = $offer !== null ? $offer->cost_minor : (int) $orderItem->unit_price_minor;
                $qtyDec = BigDecimal::of((string) $fItem->quantity);
                $lineCostMinor = (int) (string) $qtyDec->multipliedBy(BigDecimal::of($unitCostMinor));

                PurchaseOrderLine::create([
                    'tenant_id' => $fulfillment->tenant_id,
                    'purchase_order_id' => $po->id,
                    'order_item_id' => $orderItem->id,
                    'product_id' => (int) $orderItem->product_id,
                    'product_variant_id' => $orderItem->variant_id,
                    'supplier_sku' => $supplierSku,
                    'internal_sku_snapshot' => $orderItem->sku_snapshot,
                    'quantity' => (string) $fItem->quantity,
                    'unit_cost_minor' => $unitCostMinor,
                    'total_cost_minor' => $lineCostMinor,
                ]);

                $totalCostMinor += $lineCostMinor;
            }

            $po->subtotal_minor = $totalCostMinor;
            $po->total_minor = $totalCostMinor;
            $po->status = PurchaseOrderStatus::SUBMITTED->value;
            $po->submitted_at = now();
            $po->save();

            return $po->load('lines');
        });
    }
}
