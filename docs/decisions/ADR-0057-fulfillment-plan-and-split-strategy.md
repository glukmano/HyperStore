# ADR-0057: Fulfillment Plan and Multi-Source Split Strategy

## Status
Accepted

## Context
Orders may contain items sourced from multiple warehouses, suppliers, or digital fulfillment modes.

## Decision
1. `FulfillmentPlan` contains one or more `FulfillmentGroup` DTOs.
2. Deterministic baseline source selection strategy:
   - Filter eligible sources by Tenant, Store, Market, Channel, and active/fresh status.
   - Prefer a single eligible source that can satisfy all items in the request (minimize split).
   - If split is required, allocate across prioritized sources and record explicit split reasons.
3. Non-physical items (digital, service) form dedicated non-shippable fulfillment groups and bypass physical shipping rate calculation.

## Consequences
- Predictable, deterministic multi-source fulfillment planning.
