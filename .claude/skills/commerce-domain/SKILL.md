---
name: commerce-domain
description: Enforces Cart, Checkout, Master/Seller Orders, Fulfillment, and Returns domain lifecycle. Use when touching checkout flows, order management, or returns.
---

# Commerce Domain & Order Lifecycle

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 13, 17, 26)

## Core Rules & Mandates

1. **Clear Order Entity Separation**:
   - Model entities distinctly:
     ```text
     Master Order
     ├── Seller/Vendor Order (Sub-Order)
     │   └── Fulfillment(s)
     │       └── Shipment(s)
     ```
   - Never conflate Master Order, Seller Order, Fulfillment, Shipment, Payment, and Ledger Transaction into a single entity.
2. **Multi-Vendor Checkout**:
   - A single customer checkout can contain products from the platform and multiple distinct Vendors.
   - Payment is captured at the Master Order level; fulfillment and payouts split at the Seller Order level.
3. **Fulfillment Strategy**:
   - Support multiple fulfillment modes: Own Stock, Vendor Stock, Dropshipping, 3PL, Print-on-Demand, Digital Delivery, Services.
4. **Returns & RMA Lifecycle**:
   - Support item-level returns, Return Merchandise Authorization (RMA), dispute resolution, evidence attachments, and automated ledger adjustments.

## Pre-Execution Checklist
- [ ] Are Master Orders cleanly split into Seller Orders upon checkout?
- [ ] Are inventory reservations committed or released atomically?
- [ ] Are order state machine transitions strictly validated?

## Forbidden Shortcuts
- ❌ Combining Order, Payment, and Shipment into one monolithic table.
- ❌ Modifying placed orders without an explicit, auditable adjustment/amendment log.
- ❌ Overlooking fulfillment splitting across different vendor warehouses.

## Validation Steps
1. Execute multi-vendor checkout flow tests.
2. Verify order splitting, inventory deduction, and fulfillment state transitions.
3. Test return and RMA refund workflows.
