---
name: fulfillment-dropshipping
description: Enforces first-party fulfillment core, dropshipping module, supplier connectors, routing rules, and Print-on-Demand. Use when working on order fulfillment, supplier sync, or dropshipping.
---

# Fulfillment, Dropshipping & Supplier Connectors

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 13, 14, 21)

## Core Rules & Mandates

1. **Strategic First-Party Architecture**:
   - Fulfillment Core coordinates order routing and shipment lifecycle.
   - **Dropshipping is a first-party strategic module**, not an external bolt-on plugin.
   - Specific third-party supplier APIs (AliExpress, CJ, Printful, custom 3PL) are connector plugins.
2. **Supplier Model & SKU Mapping**:
   - Support platform-wide suppliers, tenant suppliers, and private vendor suppliers.
   - A single product SKU may map to multiple suppliers.
   - Real-time or scheduled synchronization of supplier stock, cost, pricing, media, and lead times.
3. **Automated Routing & Fallback**:
   - Route purchase orders based on stock availability, cost, shipping price, SLA, rating, destination, and margin.
   - Automatically fall back to secondary suppliers if primary supplier fails or runs out of stock.
4. **Print-on-Demand (POD)**:
   - First-party fulfillment workflow with artwork upload, mockup preview, variant dimensions, and provider dispatch.

## Pre-Execution Checklist
- [ ] Are supplier API integrations implemented as connector plugins?
- [ ] Is multi-supplier routing and fallback logic covered by integration tests?
- [ ] Are purchase orders created atomically with tracking updates?

## Forbidden Shortcuts
- ❌ Hardcoding specific supplier API endpoints inside core checkout or fulfillment.
- ❌ Blocking user checkout on external supplier network calls.
- ❌ Omitting fallback supplier routing rules.

## Validation Steps
1. Test supplier stock sync and price markup calculations.
2. Simulate primary supplier failure to verify fallback routing.
3. Test purchase order dispatch and tracking webhooks.
