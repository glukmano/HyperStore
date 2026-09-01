# ADR-0075: Fulfillment Plan Integration and Non-Physical Product Routing

## Status
Accepted

## Context
Orders may contain physical items, digital downloads, services, subscriptions, and licenses. The checkout process must orchestrate fulfillment planning through `modules/Fulfillment/` without duplicating fulfillment source logic.

## Decision
1. **Fulfillment Plan Invocation**:
   - Checkout calls `FulfillmentPlanningServiceInterface::plan()` passing cart line items and context.
2. **Non-Physical Partitioning**:
   - Non-physical lines (digital, service, license, subscription) are grouped into a `non_physical` fulfillment group (`FulfillmentReadiness::NON_PHYSICAL`).
   - Only physical lines participate in multi-source allocation, packing, and physical shipping quotes.
   - For digital-only checkouts, the shipping stage is marked `NO_SHIPPING_REQUIRED` and requires zero physical shipping quotes.

## Consequences
- Flawless mixed physical + digital checkout processing.
- Zero duplicated fulfillment rules in Checkout.
