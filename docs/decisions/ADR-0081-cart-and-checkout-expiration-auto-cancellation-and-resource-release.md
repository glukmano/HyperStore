# ADR-0081: Cart and Checkout Expiration, Auto-Cancellation, and Resource Release

## Status
Accepted

## Context
Abandoned carts and expired checkout sessions tie up database resources and held inventory reservations if not proactively cleaned up.

## Decision
1. **TTL Configuration**:
   - Guest carts expire after 7 days; authenticated customer carts expire after 30 days.
   - Checkout sessions expire after 60 minutes.
   - Inventory reservations expire with the checkout session TTL (e.g. 15-30 minutes).
2. **Automated Cleanup Job**:
   - An artisan command / scheduled job `hyper:checkout:cleanup-expired` finds expired checkouts, transitions them to `expired`, and releases held inventory reservations idempotently.

## Consequences
- Clean database maintenance and automatic stock replenishment on abandoned sessions.
