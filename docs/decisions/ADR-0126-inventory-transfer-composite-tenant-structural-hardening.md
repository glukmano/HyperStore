# ADR-0126: Composite Tenant-FK Structural Hardening for Transfer Tables

## Status
Accepted

## Context
Source audit confirmed zero composite FKs exist anywhere in Inventory's migrations — every FK is single-column, with tenant consistency enforced only via Eloquent `saving()` hooks. `inventory_transfer_items` has no `tenant_id` column at all. Phase-13 established the exact composite-FK syntax precedent to follow (`fk_pol_order_item`, `fk_si_tenant_po`, `fk_sil_pol_composite` in `2026_09_03_000050_create_phase_13_orders_fulfillment_dropshipping_tables.php`). Phase-14 is actively hardening the transfer subsystem (ADR-0125), making this the right moment to close the structural-integrity gap for exactly the tables being touched — without retrofitting the rest of Phase-05's schema.

## Decision
1. `inventory_sources`: add `unique(['tenant_id','id'])` — prerequisite for anything to FK against it compositely; does not exist today.
2. `inventory_transfers`: add composite FKs `(tenant_id, source_inventory_source_id)` and `(tenant_id, destination_inventory_source_id)` → `inventory_sources(tenant_id, id)`.
3. `inventory_transfer_items`: add `tenant_id` column; backfill via `UPDATE inventory_transfer_items iti SET tenant_id = it.tenant_id FROM inventory_transfers it WHERE it.id = iti.inventory_transfer_id` — 100% source-provable, zero ambiguity, no legacy/unknown bucket needed since every row has an unambiguous parent; then `NOT NULL`; then composite FK `(tenant_id, inventory_transfer_id) → inventory_transfers(tenant_id, id)`.
4. `inventory_operation_keys`: add a payload-hash column so `InventoryIdempotencyService` can detect and reject a differing payload replayed under a reused idempotency key (required by ADR-0125's `create()` contract).
5. Scope explicitly limited to the tables this phase actively modifies — no retrofit of unrelated Phase-05 tables (`stock_items`, `inventory_reservations`, etc.) is in scope here.

## Consequences
- **Positive**: the exact relationships Phase-14 is hardening now get DB-level (not just app-level) tenant-isolation guarantees, consistent with Master Section 26's "no cross-tenant unscoped data access" rule.
- **Negative**: none — both backfills are fully derivable from existing data with no fabrication.
