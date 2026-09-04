# ADR-0122: Warehouse Taxonomy — Facility Function vs. Ownership Split

## Status
Accepted

## Context
`warehouses.type`'s migration comment lists `owned, vendor, supplier, 3pl, virtual, dropship`, but the live `POST /warehouses` API validates a disjoint set: `fulfillment_center, retail_store, distribution_center, hub`. No code path anywhere writes the migration-comment's values. `InventorySource.source_type` already correctly owns the stock-provider taxonomy (`warehouse, vendor, supplier, 3pl, dropship, virtual`) per ADR-0038's Warehouse≠InventorySource split. The migration comment on `warehouses.type` is stale/duplicated documentation, not live functionality — but Master Plan Section 14 explicitly requires "Vendor warehouse" support, which needs a real ownership concept that does not exist on `Warehouse` today.

## Decision
1. `warehouses.type` is kept, unchanged in schema, and re-scoped **by documentation and validation only** to represent facility function: `fulfillment_center, retail_store, distribution_center, hub` — matching what the API already enforces. The stale migration comment is corrected; no data migration is needed for this half since no legacy value ever populated the old comment's list through any live code path.
2. A new column `warehouses.ownership_type` (`platform`, `vendor`, `3pl`) is added, additively, representing who owns/operates the physical site — a concept that did not exist before this ADR.
3. **Legacy backfill rule**: existing rows carry the schema default `type='owned'`, matching neither list. `owned` maps naturally to ownership, not facility: `UPDATE warehouses SET ownership_type='platform' WHERE type='owned'`. `type` (facility) is left unchanged/unresolved for those rows rather than guessing a facility value with no evidentiary basis — this is intentional and explicit, not a gap.

## Consequences
- **Positive**: two previously-conflated concepts (what a warehouse physically is vs. who owns it) become independently queryable and validated; no fabricated backfill.
- **Negative**: legacy rows carry an unresolved `type` value until an operator explicitly classifies them — acceptable since no code depended on the old comment's values anyway.
