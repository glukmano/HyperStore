# ADR-0071: Pricing Snapshot and Re-Calculation Strategy during Checkout

## Status
Accepted

## Context
During exploratory cart browsing, line prices reflect current catalog prices. However, during Checkout, prices, promotions, and tax amounts must be deterministically recalculated and then snapshotted before order placement.

## Decision
1. **Live Recalculation at Checkout Milestones**:
   - Checkout triggers `CartPricingService` to evaluate active price books, volume tiers, and customer group rates from `modules/Pricing/`.
2. **Price Change Detection**:
   - If catalog prices change between cart creation and checkout review, the system detects the delta, updates the checkout session, and returns a structured `PRICE_CHANGED` notification.
3. **Immutable Snapshot on Finalization**:
   - When transitioning to `ready_for_order`, the pricing breakdown is locked in an immutable JSON snapshot.

## Consequences
- Fresh prices during checkout progression.
- Immutable, auditable price snapshots for future order creation.
