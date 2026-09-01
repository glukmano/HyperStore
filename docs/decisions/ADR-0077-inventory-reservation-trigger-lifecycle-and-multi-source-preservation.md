# ADR-0077: Inventory Reservation Trigger, Lifecycle, and Multi-Source Preservation

## Status
Accepted

## Context
Holding inventory reservations during initial cart viewing leads to denial-of-inventory attacks. Conversely, placing orders without reserving stock causes overselling.

## Decision
1. **Just-In-Time Reservation**:
   - Inventory reservations are created only when a checkout reaches the `inventory_reserved` transition stage.
2. **Preservation of Multi-Source Splits**:
   - Checkout reserves stock using `InventoryReservationServiceInterface::createReservation()` per allocated fulfillment source (e.g. Source A = 6, Source B = 4).
3. **Reference Tracking**:
   - The checkout session records all reservation IDs and expiration timestamps.

## Consequences
- No stock lockup on exploratory cart browsing.
- Multi-source inventory accuracy maintained end-to-end.
