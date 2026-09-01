# ADR-0080: Immutable CheckoutReadyResult / Future Order Terminal Handoff Contract

## Status
Accepted

## Context
PHASE-07 must not implement Orders or Payments, but must output a comprehensive, immutable data contract that future Order and Payment phases can consume without re-evaluating business logic.

## Decision
1. **`CheckoutReadyResult` DTO**:
   - Final terminal output of `modules/Checkout/` containing:
     - Tenant and Store/Market/Channel context.
     - Customer contact and billing/shipping address snapshots.
     - Cart lines with unit prices, discounts, and options.
     - Final pricing totals breakdown (`merchandise_subtotal`, `discounts`, `tax_total`, `shipping_final`, `grand_total`).
     - Tax snapshot and rate breakdown.
     - Promotion and coupon benefit breakdown.
     - Fulfillment groups and selected shipping quote reference.
     - Active inventory reservation IDs.
     - Idempotency key and version fingerprint.
2. **Order Separation**:
   - Zero Order Eloquent models or database rows are created in this phase.

## Consequences
- Clean phase boundary handoff.
- 100% testable, decoupled checkout pipeline.
