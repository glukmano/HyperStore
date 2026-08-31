# ADR-0020: Canonical Product Multi-Store Publication Strategy

## Status
Accepted

## Context
Per ADR-0005, a single canonical Product must be shareable across multiple Stores, Markets, and Channels without duplicating product rows. Duplicating products across stores causes stock divergence, review fragmentation, and management nightmares.

## Decision
1. `Product` represents the singular, canonical commercial item within a Tenant.
2. Publishing a Product to a Store creates a `product_store_listings` record with:
   - `store_id` (foreign key strictly verified to belong to the same `tenant_id`)
   - `status` (`published`, `hidden`, `draft`)
   - `visibility` (`visible`, `catalog_only`, `search_only`, `hidden`)
   - `is_featured`, `sort_order`, `published_at`
3. Store-specific availability matrices:
   - `product_store_markets (listing_id, market_id, is_enabled)`
   - `product_store_channels (listing_id, channel_id, is_enabled)`
4. Products can be enabled on Store A, published to Mobile App + POS in Saudi Market, while hidden on Store B or disabled in European Market.

## Consequences
- Complete multi-store flexibility with zero canonical data duplication.
- Granular channel and market availability controls.
