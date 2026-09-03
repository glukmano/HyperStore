# ADR-0121: Line-Level RMA Returns and Marketplace Payable Refund Economics

## Status
Accepted

## Context
Customer returns (RMA) in a multi-seller environment can involve partial item quantities (e.g., returning 1 out of 3 units, or fractional decimal quantities like 0.5 kg). Refunds must be calculated at the line item level, accounting for merchandise subtotal, line discounts, allocated taxes, and vendor commissions.

Furthermore:
1. Under ADR-0101, the general Ledger is strictly movement-only (`Dr customer_funds_liability, Cr payment_clearing`). Phase-13 must not introduce direct commercial journal entries (e.g., debiting revenue or crediting cash directly).
2. Refunds must interact with the Payment bounded context and the Marketplace vendor payable subledger.
3. Multiple approvals or refund retries must be strictly idempotent and protected against concurrency races.

## Decision
1. **Fractional Quantity Allocation via Cumulative Difference-of-Floor**:
   - `DecimalReturnAllocationService` evaluates cumulative approved quantities using `brick/math`.
   - Item amounts (subtotal, discount, tax, commission) are allocated using:
     $\Delta A = \text{floor}\left(\frac{q_{\text{cum}} \times A}{Q}\right) - \text{floor}\left(\frac{q_{\text{prev}} \times A}{Q}\right)$
     with exact remainder conservation when $q_{\text{cum}} = Q$.
   - Prevents fractional-cent rounding leakage across partial returns.

2. **Durable Refund Operation UUID**:
   - Each `SellerReturn` generates and persists a durable `refund_operation_uuid` before invoking external payment gateways.
   - Retries reuse the same `refund_operation_uuid`, guaranteeing exactly one finalized refund transaction in the Payment module.

3. **Subledger Refund Adjustment (No Commercial Ledger Posting)**:
   - For vendor returns, `ReturnRefundOrchestrator` invokes `VendorPayableSubledgerService::accrueRefundAdjustment()`.
   - Debits the vendor's available payable balance while reversing the marketplace commission, guarded by the partial unique constraint `uq_payable_entries_unique_movement`.
   - The Payment refund dispatches existing payment movement events to the Ledger adapter, preserving the movement-only ledger contract.

## Consequences
- **Positive**: Perfect monetary conservation across arbitrary decimal return quantities; strict adherence to movement-only ledger principles; full idempotency and race resilience for payments and marketplace payables.
- **Negative**: Multi-step return lifecycle requires separate approval and refund finalization stages.
