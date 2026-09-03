# ADR-0120: Dropship Purchase Order Procurement and Supplier Routing Engine

## Status
Accepted

## Context
When fulfilling orders via dropshipping or print-on-demand, multiple suppliers may offer the same product variant with different costs, currencies, locations, and lead times. The platform requires a multi-supplier routing engine to select optimal offers, while strictly preserving foreign exchange (FX) auditability and preventing conflicting currency conversion engines.

Once an offer is selected, dropship fulfillment must generate persistent `PurchaseOrder` and `PurchaseOrderLine` records, which must subsequently be reconciled against external supplier invoices without data tampering or accidental deletion.

## Decision
1. **Pricing Owns FX Conversion**:
   - HyperStore maintains a single FX engine in the Pricing bounded context.
   - `CurrencyConversionInterface::convertWithAudit()` returns a `CurrencyConversionResult` containing the converted amount, exchange rate, conversion timestamp, and rate identifier.
   - `SupplierRoutingEngine` uses `convertWithAudit()` to normalize candidate supplier costs into the order's target currency and records the full conversion audit trail into the routing decision metadata.

2. **Dropship Purchase Order Orchestration**:
   - `DropshipOrderOrchestrator` generates a `PurchaseOrder` linked to the leaf `OrderFulfillment`.
   - Idempotency guarantees that replaying procurement for an already-ordered fulfillment returns the existing PO.

3. **Invoice Reconciliation & Deletion Protection**:
   - `SupplierInvoiceReconciliationService` matches incoming supplier invoice lines against purchase order lines.
   - `supplier_invoice_lines` references `purchase_order_lines` with `ON DELETE RESTRICT`, structurally preventing accidental deletion of matched PO lines.
   - Statuses are deterministically classified as `matched` or `discrepancy`.

## Consequences
- **Positive**: Complete auditability for multi-currency routing decisions; strictly prevents duplicate FX engines; protects historical procurement records from deletion.
- **Negative**: Routing requires real-time FX rate lookups and currency conversion records.
