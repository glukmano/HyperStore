# ADR-0078: Reservation Consistency Model — Single Transaction Atomic Rollback

## Status
Accepted

## Context
When reserving stock across multiple inventory sources (e.g. Source A = 6, Source B = 4), a partial failure (e.g. Source A succeeds, but Source B fails) would leave orphaned reservations and corrupted stock levels if not handled atomically.

## Decision
1. **Option A — Single Database Transaction**:
   - Inventory reservation operations execute within the caller's outer PostgreSQL database transaction (`DB::transaction()`).
   - Source allocations are locked in deterministic order (`inventory_source_id ASC, product_id ASC, variant_id ASC`) to prevent deadlocks.
   - If any allocated source has insufficient stock or throws an exception, an `InventoryUnavailableException` is thrown, causing PostgreSQL to execute an immediate atomic rollback of all database modifications in that step.
2. **No Orphaned State**:
   - Transactional rollback guarantees zero orphaned database records and zero leaked stock holds without relying on asynchronous or best-effort compensation.

## Consequences
- Strict database-level atomicity.
- 100% deadlock-safe and leak-safe inventory securing.
