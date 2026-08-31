# ADR-0047: Inventory Reconciliation and Reservation Integrity Strategy

## Status
Accepted

## Context
Over time, software bugs or concurrent interruptions could cause discrepancies between materialized stock balances and raw movement logs.

## Decision
1. Dual Reconciliation:
   - Movement Audit: Compares `stock_items.on_hand` against `SUM(inventory_movements.quantity_delta)`.
   - Reservation Audit: Compares `stock_items.reserved` against `SUM(active inventory_reservation_allocations.quantity)`.
   - Parent-Child Audit: Validates parent reservation quantity matches child allocation totals.
2. Policy:
   - Default mode is report-only (identifies anomalies and emits diagnostic reports).
   - Silent stock modification is strictly prohibited.

## Consequences
- Automated anomaly detection preserving system integrity and providing clear audit diagnostics.
