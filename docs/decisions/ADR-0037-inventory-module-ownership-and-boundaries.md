# ADR-0037: Inventory Module Ownership and Boundaries

## Status
Accepted

## Context
HyperStore requires an inventory subsystem to manage physical stock, stock reservations, warehouse locations, and availability tracking across multiple stores, markets, and channels.

## Decision
1. Create `modules/Inventory/` as a dedicated first-party module.
2. Inventory owns:
   - Warehouses and physical storage facilities
   - Inventory Sources (owned, vendor, supplier, 3PL, virtual)
   - Units of Measure (UoM)
   - Stock Item balances (`on_hand`, `reserved`, `quarantined`, `damaged`, `incoming`)
   - Immutable Inventory Movement ledger
   - Inventory Reservations and split multi-source allocations
   - Inter-warehouse transfers
   - Available-to-Sell (ATS) computation
   - Low-stock and Out-of-stock detection
   - Inventory reconciliation diagnostics
3. Inventory does NOT own:
   - Product/Variant identity (Catalog domain)
   - Commercial pricing (Pricing domain)
   - Orders/Checkout (future Orders/Checkout domain)
   - Financial ledger/accounting (future Ledger domain)
   - Shipping rates and carrier execution (future Shipping domain)

## Consequences
- Clean separation between product definition, pricing, stock truth, and commercial transactions.
- Future modules interact with Inventory through strict contracts.
