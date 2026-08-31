# ADR-0038: Warehouse vs Inventory Source Architecture

## Status
Accepted

## Context
A physical warehouse is not the only source of inventory in modern commerce. Future sources include Vendor marketplace stock, Supplier API feeds, 3PL providers, Print-on-Demand providers, and virtual unlimited inventory.

## Decision
1. Decouple `Warehouse` from `InventorySource`:
   - `Warehouse`: Represents a physical site or facility with an address, timezone, geographic coordinates, and operational parameters.
   - `InventorySource`: Represents a logical stock provider. An inventory source may link to a physical `warehouse_id` (for owned facilities) or have a `null` warehouse_id (for external API feeds, virtual sources, or vendor drop-shippers).
2. Stock Items belong directly to an `InventorySource` (`stock_items.inventory_source_id`).
3. Store, Market, and Channel scoping pivots are attached at the `InventorySource` level.
4. `InventorySource` tracks external synchronization metadata (`last_synced_at`, `stale_after_minutes`).

## Consequences
- Allows seamless addition of external, drop-shipped, or virtual inventory sources without creating fake physical warehouses.
