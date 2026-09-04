# ADR-0129: Inventory Scale-4 / Order-Dropshipping Scale-8 Quantity Boundary

## Status
Accepted

## Context
Source audit confirmed a real cross-module NUMERIC scale mismatch: Inventory is uniformly `NUMERIC(14,4)` (`stock_items.*`, `inventory_transfer_items.*`, `inventory_movements.quantity_delta/resulting_on_hand`), while Order (`order_items.quantity`) and Dropshipping (`purchase_order_lines.quantity`, `supplier_invoice_lines.quantity`, return-quantity columns) are uniformly `NUMERIC(20,8)`. This phase's RMA-restock integration (ADR-0128) is the first code path to move a quantity value from Order/Dropshipping scale into Inventory scale.

## Decision
1. Inventory's `Quantity` value object and all Inventory-owned columns remain scale-4, unchanged by this phase.
2. Any quantity crossing from an Order/Dropshipping-scale (8) source into an Inventory-scale (4) field must pass an explicit boundary check, placed in the boundary/adapter code (the RMA-restock listener) — not in Inventory's core `Quantity` VO.
3. **Fail closed**: if the source value has any non-zero digit beyond the 4th decimal place, the boundary check throws a typed exception rather than narrowing. Otherwise, it narrows safely (drops only trailing zero digits beyond scale 4).
4. Explicit test required: a >4-decimal-place input from an Order/Dropshipping-scale quantity is rejected, never silently rounded.

## Consequences
- **Positive**: prevents silent precision loss at a real cross-module boundary — a genuine correctness risk that predates this phase but was previously never exercised (no code moved quantities across this boundary before).
- **Negative**: a return quantity expressed with more than 4 decimal places (a legitimate possibility given Order's scale-8 columns) will be rejected rather than restocked automatically — acceptable given Master Section 26's "no float for money"-adjacent principle of never silently losing precision; an operator must resolve such cases manually.
