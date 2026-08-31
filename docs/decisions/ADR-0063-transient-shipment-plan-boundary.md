# ADR-0063: Transient ShipmentPlan vs Future Persistent Shipment Boundary

## Status
Accepted

## Context
Determining whether PHASE-06 should introduce persistent `Shipment` database models or maintain transient plans until checkout/order creation.

## Decision
1. In PHASE-06, shipment packaging and fulfillment allocations are strictly modeled as transient DTOs (`FulfillmentPlan`, `ShipmentPlan`, `PackageCandidate`).
2. Persistent `Shipment` models will be introduced during Orders & Fulfillment execution phases when real order IDs exist.
3. No premature database persistence of customer shipments or orders in PHASE-06.

## Consequences
- Zero unattached orphan shipment rows in the database.
