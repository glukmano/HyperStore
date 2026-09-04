# ADR-0124: External Supplier Stock Provider SPI (ADR-0048 Completion)

## Status
Accepted

## Context
ADR-0048 pre-declared `inventory_sources.source_type` values `vendor|supplier|3pl|dropship|virtual` with freshness tracking (`last_synced_at`/`stale_after_minutes`) and explicitly deferred an `ExternalStockProviderInterface`, which was never implemented. Master Plan Section 14 requires "supplier warehouse" support. Phase-13 established `SupplierLocation`/`Supplier` as structurally distinct from `Warehouse`/`InventorySource` (no shared FK, ADR-0119) — that boundary must not be broken to satisfy this requirement.

## Decision
1. `Modules\Inventory\Contracts\ExternalStockProviderInterface` (Inventory-owned): `getAvailability(InventorySource $source): ExternalStockSnapshotDTO`, consumed only when `InventorySource.source_type='supplier'|'vendor'`.
2. Concrete implementation lives in Dropshipping, not Inventory: `Modules\Dropshipping\Adapters\SupplierExternalStockProvider implements ExternalStockProviderInterface`, resolving the existing `InventorySource.external_reference` column (reused as an opaque cross-domain identity) to a concrete `Supplier`/`SupplierLocation`. Dependency direction is Dropshipping → Inventory-interface only; Inventory never imports a Supplier Eloquent model — mirroring the Ledger unidirectional-dependency convention (ADR-0101).
3. Fail-closed: resolution failure or timeout → `readiness=unavailable` (existing `SourceAvailabilityDTO` value), never assumed-available.
4. Authorization: the adapter reuses the existing Supplier scope check already built in `DropshipOrderOrchestrator` (platform/tenant/private-vendor `TenantSupplierAccess` check).
5. **No mutation path**: external quantity is surfaced live, read-time only, through the existing `InventorySourceQueryInterface`/`SourceAvailabilityDTO` — it is never written into `stock_items.on_hand`. This eliminates sync-idempotency concerns entirely since nothing is persisted.

## Consequences
- **Positive**: completes a three-phase-old deferred seam without merging Supplier and Warehouse domains; zero risk of external stock becoming falsely-owned internal inventory.
- **Negative**: availability checks against supplier sources incur a live adapter call at read time rather than a cached write — acceptable given the existing freshness/staleness tracking already accounts for this.
