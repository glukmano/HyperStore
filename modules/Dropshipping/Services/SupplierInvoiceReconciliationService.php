<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Services;

use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Dropshipping\Contracts\SupplierInvoiceReconciliationServiceInterface;
use Modules\Dropshipping\Enums\SupplierInvoiceReconciliationStatus;
use Modules\Dropshipping\Models\PurchaseOrder;
use Modules\Dropshipping\Models\PurchaseOrderLine;
use Modules\Dropshipping\Models\SupplierInvoice;
use Modules\Dropshipping\Models\SupplierInvoiceLine;

class SupplierInvoiceReconciliationService implements SupplierInvoiceReconciliationServiceInterface
{
    public function recordAndReconcileInvoice(
        PurchaseOrder $po,
        string $invoiceNumber,
        array $lines
    ): SupplierInvoice {
        if (empty($lines)) {
            throw new InvalidArgumentException('Invoice must contain at least one line.');
        }

        return DB::transaction(function () use ($po, $invoiceNumber, $lines): SupplierInvoice {
            $po->loadMissing('lines');
            $poLinesMap = $po->lines->keyBy('id');

            // 1. Create Invoice Header
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $po->tenant_id,
                'supplier_id' => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'invoice_number' => $invoiceNumber,
                'currency' => $po->currency,
                'subtotal_minor' => 0,
                'tax_minor' => 0,
                'shipping_minor' => 0,
                'total_minor' => 0,
                'status' => 'received',
                'issued_at' => now(),
                'metadata' => [
                    'reconciliation_status' => SupplierInvoiceReconciliationStatus::PENDING->value,
                ],
            ]);

            $totalInvoiceMinor = 0;
            $hasDiscrepancy = false;

            foreach ($lines as $lineData) {
                $poLineId = (int) $lineData['purchase_order_line_id'];
                /** @var PurchaseOrderLine|null $poLine */
                $poLine = $poLinesMap->get($poLineId);

                if ($poLine === null) {
                    throw new DomainException("PurchaseOrderLine [{$poLineId}] does not belong to PO [{$po->id}].");
                }

                $qty = (string) $lineData['quantity'];
                $unitCost = (int) $lineData['unit_cost_minor'];
                $lineTotal = (int) (string) BigDecimal::of($qty)->multipliedBy(BigDecimal::of($unitCost));

                // Check discrepancies against PO line
                if (BigDecimal::of($qty)->compareTo(BigDecimal::of((string) $poLine->quantity)) !== 0) {
                    $hasDiscrepancy = true;
                }
                if ($unitCost !== $poLine->unit_cost_minor) {
                    $hasDiscrepancy = true;
                }

                SupplierInvoiceLine::create([
                    'supplier_invoice_id' => $invoice->id,
                    'purchase_order_id' => $po->id,
                    'purchase_order_line_id' => $poLine->id,
                    'supplier_sku_snapshot' => $poLine->supplier_sku,
                    'description' => 'Invoice line for PO Line '.$poLine->id,
                    'quantity' => $qty,
                    'unit_cost_minor' => $unitCost,
                    'line_total_minor' => $lineTotal,
                ]);

                $totalInvoiceMinor += $lineTotal;
            }

            if ($totalInvoiceMinor !== $po->total_minor) {
                $hasDiscrepancy = true;
            }

            $status = $hasDiscrepancy
                ? SupplierInvoiceReconciliationStatus::DISCREPANCY->value
                : SupplierInvoiceReconciliationStatus::MATCHED->value;

            $invoice->update([
                'subtotal_minor' => $totalInvoiceMinor,
                'total_minor' => $totalInvoiceMinor,
                'metadata' => [
                    'reconciliation_status' => $status,
                    'reconciled_at' => $hasDiscrepancy ? null : now()->toIso8601String(),
                ],
            ]);

            $fresh = $invoice->fresh(['lines']);

            return $fresh instanceof SupplierInvoice ? $fresh : $invoice;
        });
    }
}
