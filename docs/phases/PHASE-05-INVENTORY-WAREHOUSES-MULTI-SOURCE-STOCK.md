# PHASE-05 Specification: Inventory, Warehouses & Multi-Source Stock

**Phase Status**: `IN PROGRESS`  
**Start Date**: 2026-08-31  
**Module**: `modules/Inventory/`  
**Governing Authority**: `PROJECT_MASTER_PLAN.md`

---

## 1. Overview & Architectural Invariants

Phase 05 establishes the complete inventory, warehouse operations, multi-source availability, and concurrency-safe reservation engine for the Hyper Commerce Platform.

### Invariants:
1. **Catalog Decoupling**: Catalog owns Product and Variant identity; Pricing owns price; Inventory owns physical and virtual stock truth, inventory sources, locations, movements, and reservations.
2. **Inventory Source Abstraction (`Warehouse != InventorySource`)**:
   - `warehouses` represents physical sites and facilities with geographic addresses.
   - `inventory_sources` represents stock providers (owned warehouses, vendor stock, supplier API stock, 3PLs, print-on-demand, virtual sources).
   - Stock items belong to `inventory_sources`.
3. **Decimal Quantity Precision**:
   - Quantities are stored as `NUMERIC(14, 4)` in PostgreSQL.
   - Handled via immutable `Quantity` Value Object with `bcmath` operations.
   - Construction from binary float is strictly prohibited (`Quantity::fromString()`, `Quantity::fromInteger()`).
4. **Unit of Measure (UoM) Foundation**:
   - Standard units: `piece`, `kg`, `g`, `meter`, `cm`, `liter`.
   - Extensible unit metadata without hardcoded enums.
5. **Stock Balance & Condition Buckets**:
   - `on_hand`: Authoritative physical balance maintained transactionally via `inventory_movements`.
   - `reserved`: Derived/materialized from active `inventory_reservation_allocations`.
   - `quarantined`, `damaged`, `incoming`: Explicit status allocations.
   - `available_to_sell`: Derived: `max(0, on_hand - reserved - quarantined - damaged)`.
6. **Immutable Stock Movement Ledger**:
   - All stock mutations create an immutable `inventory_movements` entry. Corrections use compensating movements.
7. **Pessimistic Concurrency & Lock Ordering**:
   - Reservations and deductions lock target `stock_items` rows in deterministic ID order (`SELECT ... FOR UPDATE`) inside atomic database transactions to eliminate overselling and deadlocks.
8. **Multi-Source Split Allocations**:
   - `inventory_reservations` represents logical reservations.
   - `inventory_reservation_allocations` allocates stock across multiple eligible sources.
9. **Backorder & Preorder Policies**:
   - Backorder modes: `deny`, `allow`, `allow_with_limit`.
   - Precedence: Product/Variant override → Store override → Source override → Tenant default.
10. **Idempotency Strategy**:
    - All external or retryable inventory mutations (receive, adjust, reserve, commit, transfer) enforce idempotency via `inventory_operation_keys`.
11. **Reconciliation**:
    - Compares `stock_items.on_hand` against movement ledger sums AND validates active reservation allocation totals.
12. **Scope Boundaries**:
    - No Cart, Checkout, Order, Payment, Shipping rate, or Financial Ledger models are created.

---

## 2. Database Schema

1. `warehouses`: Physical facilities (`code`, `name`, `country_code`, `timezone`, `address`, etc.)
2. `inventory_sources`: Stock sources (`warehouse_id` nullable, `source_type`, `code`, `name`, `priority`, `last_synced_at`, `stale_after_minutes`, `status`)
3. `inventory_source_store_assignments`, `inventory_source_market_assignments`, `inventory_source_channel_assignments`: Scoping pivots.
4. `units_of_measure`: `code`, `name`, `symbol`, `scale`, `status`.
5. `stock_items`: `tenant_id`, `inventory_source_id`, `product_id`, `product_variant_id`, `on_hand`, `reserved`, `quarantined`, `damaged`, `incoming`, `low_stock_threshold`, `backorder_mode`, `backorder_limit`, `tracking_mode`, `unit_of_measure_code`.
6. `inventory_movements`: `tenant_id`, `stock_item_id`, `inventory_source_id`, `product_id`, `product_variant_id`, `quantity_delta`, `resulting_on_hand`, `movement_type`, `reference_type`, `reference_id`, `causation_id`, `idempotency_key`, `reason`.
7. `inventory_reservations` & `inventory_reservation_allocations`: Multi-source split reservations.
8. `inventory_transfers` & `inventory_transfer_items`: Inter-warehouse stock transfers.
9. `inventory_operation_keys`: Idempotency tracking table.

---

## 3. Core Services & Commands

- `InventoryAvailabilityService`: Aggregates ATS across eligible sources for given Store, Market, Channel.
- `InventoryReservationService`: Manages `reserve()`, `release()`, `expire()`, `commit()` under deterministic row locking.
- `InventoryAdjustmentService`: Handles manual restock, damage, and recount adjustments.
- `InventoryTransferService`: Handles draft -> requested -> in_transit -> received workflow.
- `InventoryReconciliationService`: Detects balance drift and reservation anomalies.
- `inventory:expire-reservations`: Cron command for expiring stale reservations.
- `inventory:reconcile`: Diagnostic command for detecting inventory anomalies.
