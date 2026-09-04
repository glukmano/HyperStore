# PHASE-14: Inventory Operations Extension

> **Authority**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) — Section 14 (Inventory/Warehouses)
> **Status**: COMPLETED — ACCEPTANCE CANDIDATE (implementation finished, all gates green; owner acceptance pending — not self-marked owner-accepted)
> **Active Dates**: 2026-09-04 to 2026-09-04

---

## 1. Objective

Extend the Phase-05 Inventory/Warehouse bounded context to close the concrete gaps left open by Master Plan Section 14's text and by Phase-05 itself: Warehouse taxonomy correction, Vendor-warehouse ownership, a read-only External Supplier Stock port (completing ADR-0048's deferred seam), formal `InventoryTransfer` creation with real conservation equations and reservation-safety, structural (PostgreSQL composite-FK) tenant hardening on the transfer tables, a preorder dead-code fix, and RMA physical-restock integration. Master Plan Section 14 was already largely delivered by Phase-05 (COMPLETED); this phase closes the remainder, not a redo.

## 2. Included Scope

- Warehouse taxonomy split: `warehouses.type` re-scoped to facility function only (unchanged column, corrected documentation/validation); new `warehouses.ownership_type` column (`platform`/`vendor`/`3pl`).
- Vendor-warehouse ownership: `warehouses.vendor_id` (composite FK `(tenant_id, vendor_id) → vendors(tenant_id, id)`), suspension-gated mutation using the existing `VendorOperationalStatusException` pattern.
- `ExternalStockProviderInterface` (Inventory-owned contract) + `SupplierExternalStockProvider` (Dropshipping-owned adapter), read-only, never writes `stock_items.on_hand`.
- `InventoryTransferServiceInterface::create()` — formal, idempotency-wrapped, transactional header+items creation.
- Transfer conservation equations activating `stock_items.incoming` (dead column today) with a new `InventoryMovement` type for the dispatch-time destination-incoming change, plus damaged/quarantine receipt disposition.
- Transfer dispatch fixed to respect `reserved`/`available_to_sell`, not raw `on_hand` (closes a confirmed live oversell defect).
- Warehouse/InventorySource deactivation enforcement across transfer create/dispatch (receive intentionally unaffected — historical completion path).
- Composite tenant FKs: `inventory_sources` unique `(tenant_id, id)`; `inventory_transfers` composite FKs to `inventory_sources`; `inventory_transfer_items` gains `tenant_id` (source-provable backfill) + composite FK to `inventory_transfers`.
- Preorder: `InventorySourceQueryService` fixed to derive readiness from Catalog's `Product.product_type==='preorder'` instead of the dead `stock_items.is_preorder` read. No new Inventory column.
- RMA physical-restock integration: `return_items.restock_action` (`restock`/`quarantine`/`discard`/`return_to_supplier`) wired to Inventory via a disposition-triggered event, architecturally decoupled from `ReturnRefundOrchestrator::finalizeRefund()`.
- Scale-4/scale-8 cross-module quantity boundary: fail-closed narrowing check at the RMA-restock integration point.
- `InventoryIdempotencyService` payload-hash enhancement (differing payload under a reused idempotency key must fail closed, not silently replay the first result).
- Real PostgreSQL multi-process concurrency tests for the 11-race matrix (frozen plan §20/Revision-2 §E).

## 3. Explicitly Excluded Scope

- Warehouse-bound/replenishment `PurchaseOrder` receiving (no such concept exists in Phase-13 source today — every `PurchaseOrder.type` is `dropship`; building this requires new Phase-13 schema and belongs in a separate RFC).
- Automatic replenishment/reorder-point/safety-stock engine.
- Bin/aisle/zone-level WMS granularity.
- Inventory valuation, COGS, or any financial Ledger posting from Inventory.
- Redis-based coordination (no evidence found that PostgreSQL row-locking is insufficient).
- Any Phase-15+ feature.

## 4. Required Skills

`project-governance`, `postgresql-data-design`, `laravel-platform`, `multi-tenancy`, `commerce-domain`, `testing-quality`.

## 5. Prerequisites

Phase-05 (Inventory, COMPLETED), Phase-08 (Order — reservation adoption, COMPLETED), Phase-11 (Marketplace/Vendor, COMPLETED — `Vendor`, `VendorOperationalStatus`), Phase-13 (Orders/Fulfillment/Dropshipping/RMA, COMPLETED, closed commit `9353ff6`).

## 6. Architecture & ADRs

Adheres to: ADR-0037 through ADR-0048 (Inventory bounded-context ownership, Warehouse-vs-InventorySource split, concurrency/locking, reservation lifecycle, transfer workflow, idempotency, external-stock extension boundary), ADR-0101 (Ledger movement-only boundary — not touched by this phase), ADR-0119 (Supplier scope structural isolation — `SupplierLocation` stays distinct from `Warehouse`/`InventorySource`).

Creates: ADR-0122 through ADR-0129 (see `docs/decisions/`).

## 7. Database Work

Additive migrations only — no Phase-05 or Phase-13 migration file is edited:
1. `warehouses`: add `ownership_type`, `vendor_id` (nullable), composite FK to `vendors(tenant_id, id)` restrictOnDelete.
2. `inventory_sources`: add `unique(tenant_id, id)`.
3. `inventory_transfers`: add composite FKs to `inventory_sources(tenant_id, id)` for both source and destination.
4. `inventory_transfer_items`: add `tenant_id` (source-provable backfill from parent `inventory_transfers` row, then `NOT NULL`), composite FK to `inventory_transfers(tenant_id, id)`.
5. `inventory_movements`: extend `VALID_TYPES` with the new dispatch-time incoming movement type (app-level const, no schema change).
6. `inventory_operation_keys`: add a payload-hash column for conflicting-payload detection under a reused idempotency key.

## 8. Backend Work

`InventoryTransferService::create()` (new); `dispatch()`/`receive()` updated for reservation-safety and conservation equations; `ExternalStockProviderInterface` + Dropshipping adapter; `InventorySourceQueryService` preorder-trigger fix; Vendor-warehouse suspension guard (reusing `VendorOperationalStatusException`); RMA disposition listener wiring `ReturnItem.restock_action` to Inventory services; scale-boundary check utility.

## 9. Frontend Work

None planned this phase (no UI requirement identified in Master Section 14's text; existing `TransferManager` Livewire component gets the same status-filter fix applied to its warehouse picker if touched incidentally).

## 10. API Work

Extend existing `modules/Inventory/Routes/api.php` transfer-creation endpoint to call the new formal `create()` service method instead of ad hoc `::create()` calls.

## 11. Security

Tenant isolation via new composite FKs (DB-enforced, not just app-level); Vendor-scope isolation (Vendor A cannot mutate Vendor B's warehouse); permission additions following the existing `{plural_resource}.{action}` Spatie convention.

## 12. Tests

Per the frozen plan's §22/test matrix — unit, feature, 11 real multi-process PostgreSQL concurrency races, structural-integrity (raw-insert rejection) tests for every new composite FK, and full regression of the existing Inventory/Order/Fulfillment/Dropshipping/Marketplace suites.

## 13. Documentation

This file; ADR-0122–0129; `docs/PROJECT_REMAINING_IMPLEMENTATION_ROADMAP.md` (already created, informational).

## 14. Acceptance Criteria

- [ ] All unit and feature tests pass, 100% green.
- [ ] Larastan/PHPStan Level 8, zero errors.
- [ ] Laravel Pint clean.
- [ ] 11 real PostgreSQL concurrency races pass with production service calls.
- [ ] Structural-integrity tests prove Postgres (not just app code) rejects invalid composite-FK rows.
- [ ] Migration rollback/re-apply verified.
- [ ] Tenant/Vendor isolation boundaries verified.
- [ ] `PROJECT_MASTER_PLAN.md`, Phase-05, and Phase-13 contracts unchanged.

## 15. Stop Condition

When all acceptance criteria are satisfied: run all tests/linters, produce the Phase-14 completion report, commit, push to `origin/main` (no force push), and **STOP and wait for user instruction before beginning Phase-15**.
