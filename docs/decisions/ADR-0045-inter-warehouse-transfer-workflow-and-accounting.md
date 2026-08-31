# ADR-0045: Inter-Warehouse Transfer Workflow and Accounting

## Status
Accepted

## Context
Merchants transfer inventory between warehouses. In-transit stock must not be available for sale at either location.

## Decision
1. Transfer Lifecycle:
   - `draft` -> `requested` -> `in_transit` -> `received` / `cancelled`.
2. Inventory Accounting:
   - Upon `dispatch` (transition to `in_transit`): Source `on_hand` is decremented via `transfer_out` movement. Source stock is no longer sellable.
   - Upon `receive` (transition to `received`): Destination `on_hand` is incremented via `transfer_in` movement.
3. Quantity Tracking:
   - Tracks `requested_quantity`, `dispatched_quantity`, `received_quantity`.
   - Over-receipt (receiving more than dispatched) is rejected.

## Consequences
- Accurate multi-location inventory accounting without in-transit stock leakage.
