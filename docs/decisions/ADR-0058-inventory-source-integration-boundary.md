# ADR-0058: InventorySource Integration Boundary and Pure Fulfillment Eligibility

## Status
Accepted

## Context
Fulfillment planning requires inventory availability without mutating or duplicating inventory state.

## Decision
1. `FulfillmentPlanningService` calls `InventoryAvailabilityService` and `InventorySourceEligibilityService` purely as read-only queries.
2. Zero stock reservations, movements, or mutations occur during fulfillment planning or shipping rate quoting.
3. Stock reservation is strictly deferred to checkout execution in PHASE-07.

## Consequences
- Fulfillment planning is 100% idempotent, stateless, and pure.
