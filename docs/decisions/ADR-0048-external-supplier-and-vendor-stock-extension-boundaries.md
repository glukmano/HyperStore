# ADR-0048: External Supplier and Vendor Stock Extension Boundaries

## Status
Accepted

## Context
Future marketplace vendors and dropshipping suppliers will supply external stock feeds that synchronize periodically.

## Decision
1. `inventory_sources` supports `source_type` (`vendor`, `supplier`, `3pl`, `dropship`, `virtual`).
2. Freshness Tracking: `last_synced_at` and `stale_after_minutes`. If `now() - last_synced_at > stale_after_minutes`, stock is flagged as stale.
3. Extension Contracts: Define `ExternalStockProviderInterface` without implementing external API integrations in Phase 05.

## Consequences
- Establishes clean architectural seams for future dropshipping and vendor modules.
