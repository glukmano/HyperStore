# ADR-0040: Stock Balance, Condition Buckets and Movement Ledger Model

## Status
Accepted

## Context
Inventory balances must be accurate, auditable, and categorized into condition states without redundant mutable columns that can drift out of sync.

## Decision
1. `stock_items` maintains explicit condition balances:
   - `on_hand`: Physical on-site quantity at the source, updated transactionally via movements.
   - `reserved`: Active reserved quantity, updated transactionally with reservation allocations.
   - `quarantined`: Stock held for inspection/quality assurance (not sellable).
   - `damaged`: Defective or broken stock (not sellable).
   - `incoming`: Expected inventory from supplier or transfer.
2. `available_to_sell` (ATS): Computed as `max(0, on_hand - reserved - quarantined - damaged)`.
3. `inventory_movements`: Immutable audit ledger. Direct editing or deletion of movements is strictly forbidden. Corrections are made via compensating movements.

## Consequences
- Full traceability of every stock unit from receipt to shipment commit.
