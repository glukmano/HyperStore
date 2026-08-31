# ADR-0043: Multi-Source Stock Aggregation and Allocation Routing Foundation

## Status
Accepted

## Context
When a customer browses a product, stock may be distributed across multiple sources eligible for the current Store, Market, and Channel.

## Decision
1. `InventoryAvailabilityService`:
   - Filters active inventory sources matching the current Tenant, Store, Market, and Channel.
   - Evaluates source freshness (`stale_after_minutes`).
   - Aggregates Available-to-Sell quantities across eligible sources.
2. Allocation Routing:
   - When reserving, allocates from highest-priority eligible sources first.
   - Supports splitting a requested quantity across multiple sources if a single source lacks sufficient stock.

## Consequences
- Provides flexible multi-warehouse fulfillment capabilities without coupling to shipping logistics.
