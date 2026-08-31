# ADR-0042: Inventory Reservation Lifecycle and Split Multi-Source Allocation

## Status
Accepted

## Context
Reservations hold inventory for an in-progress cart or checkout session before an order is placed. An order may require stock from multiple sources.

## Decision
1. Two-Tier Model:
   - `inventory_reservations`: Logical reservation parent with a unique `reservation_key`, status (`active`, `committed`, `released`, `expired`), and expiration timestamp (`expires_at`).
   - `inventory_reservation_allocations`: Child records allocating specific quantities from individual `stock_items`.
2. Invariant: Parent reserved quantity equals the sum of child allocation quantities.
3. Lifecycle Transitions:
   - `reserve`: Locks rows, checks ATS, creates reservation and allocations, increments `stock_items.reserved`.
   - `release`: Decrements `stock_items.reserved`, marks reservation `released`.
   - `expire`: Same as release, marks reservation `expired`.
   - `commit`: Decrements `stock_items.reserved` and `stock_items.on_hand`, writes `reservation_commit` movement, marks reservation `committed`. Double commit or commit after release/expire is strictly rejected.

## Consequences
- Clean reservation management ready for future checkout and order workflows.
