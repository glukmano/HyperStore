# ADR-0127: Preorder Readiness Ownership Correction

## Status
Accepted

## Context
Master Plan Section 14 lists "preorders" explicitly. Source audit found a real, three-module seam broken at exactly one join point: Catalog's `PreorderProductType` is a genuinely registered product type (`product_type='preorder'`); Inventory's `SourceAvailabilityDTO::PREORDER` and `canFulfillQuantity()` are real code; but `InventorySourceQueryService` triggers readiness via `$stockItem->is_preorder`, a column that does not exist on `stock_items` (always evaluates `false` — confirmed dead, same defect class as `incoming`). Fulfillment's `FulfillmentReadiness::PREORDER` handling in `FulfillmentPlanningService` (both single-source and split-allocation paths) is already correctly wired to consume Inventory's signal and requires zero changes. `order_items.product_type_snapshot` already exists and is already populated at checkout time from the line's product type — an order on a preorder-type product already carries `product_type_snapshot='preorder'` today.

## Decision
1. `InventorySourceQueryService` derives preorder readiness from Catalog's `Product.product_type==='preorder'` (the true owning fact) instead of the dead Inventory-owned flag. **No new `stock_items` column is added.**
2. Fulfillment requires no change — it already correctly consumes the corrected signal.
3. Order requires no new field — `product_type_snapshot`, already frozen at checkout, already carries the customer-facing preorder marker.
4. `available_to_sell`'s numeric formula is unchanged — preorder readiness stays on the separate `SourceAvailabilityDTO.readiness` enum, never mutating the availability quantity itself (this separation already exists in code and is preserved, not newly built).

## Consequences
- **Positive**: satisfies Master's "preorders" clause with a one-line ownership correction plus removal of dead code, not a new subsystem; zero new Inventory schema.
- **Negative**: preorder remains a coarse boolean-equivalent signal (no ETA/deposit modeling) — acceptable since Master's text does not request that level of detail; a future phase can extend it without re-touching this fix.
