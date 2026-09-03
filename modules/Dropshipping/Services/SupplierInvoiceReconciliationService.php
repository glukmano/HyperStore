<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
        array $lines,
        ?int $shippingMinor = null,
        ?int $taxMinor = null
    ): SupplierInvoice {
        if (empty($lines)) {
            throw new InvalidArgumentException('Invoice must contain at least one line.');
        }

        return DB::transaction(function () use ($po, $invoiceNumber, $lines, $shippingMinor, $taxMinor): SupplierInvoice {
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

            $sumLineSubtotal = 0;
            $sumLineTax = 0;
            $hasDiscrepancy = false;

            foreach ($lines as $lineData) {
                $poLineId = (int) $lineData['purchase_order_line_id'];
                /** @var PurchaseOrderLine|null $poLine */
                $poLine = $poLinesMap->get($poLineId);

                if ($poLine === null) {
                    throw new DomainException("PurchaseOrderLine [{$poLineId}] does not belong to PO [{$po->id}].");
                }

                $qtyDec = BigDecimal::of((string) $lineData['quantity']);
                $unitCostMinor = (int) $lineData['unit_cost_minor'];
                $lineTaxMinor = (int) ($lineData['tax_minor'] ?? 0);
                $lineTotalMinor = $qtyDec->multipliedBy(BigDecimal::of($unitCostMinor))->toScale(0, RoundingMode::HalfUp)->toInt();

                // Check discrepancies against PO line
                if ($qtyDec->compareTo(BigDecimal::of((string) $poLine->quantity)) !== 0) {
                    $hasDiscrepancy = true;
                }
                if ($unitCostMinor !== $poLine->unit_cost_minor) {
                    $hasDiscrepancy = true;
                }

                SupplierInvoiceLine::create([
                    'supplier_invoice_id' => $invoice->id,
                    'purchase_order_id' => $po->id,
                    'purchase_order_line_id' => $poLine->id,
                    'supplier_sku_snapshot' => $poLine->supplier_sku,
                    'description' => 'Invoice line for PO Line '.$poLine->id,
                    'quantity' => (string) $qtyDec,
                    'unit_cost_minor' => $unitCostMinor,
                    'line_total_minor' => $lineTotalMinor,
                    'tax_minor' => $lineTaxMinor,
                ]);

                $sumLineSubtotal += $lineTotalMinor;
                $sumLineTax += $lineTaxMinor;
            }

            $effectiveTaxMinor = $taxMinor ?? $sumLineTax;
            if ($taxMinor !== null && $taxMinor !== $sumLineTax && $sumLineTax > 0) {
                throw new DomainException("Supplier invoice tax [{$taxMinor}] does not match sum of line taxes [{$sumLineTax}].");
            }

            $effectiveShippingMinor = $shippingMinor ?? 0;
            $effectiveTotalMinor = $sumLineSubtotal + $effectiveTaxMinor + $effectiveShippingMinor;

            // Discrepancy against PO
            if ($effectiveTotalMinor !== $po->total_minor || $sumLineSubtotal !== $po->subtotal_minor) {
                $hasDiscrepancy = true;
            }

            $status = $hasDiscrepancy
                ? SupplierInvoiceReconciliationStatus::DISCREPANCY->value
                : SupplierInvoiceReconciliationStatus::MATCHED->value;

            $invoice->update([
                'subtotal_minor' => $sumLineSubtotal,
                'tax_minor' => $effectiveTaxMinor,
                'shipping_minor' => $effectiveShippingMinor,
                'total_minor' => $effectiveTotalMinor,
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
