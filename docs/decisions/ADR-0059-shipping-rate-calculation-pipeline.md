# ADR-0059: Shipping Rate Calculation Pipeline and Deterministic Ordering

## Status
Accepted

## Context
Calculating shipping options requires a multi-stage pipeline that remains pure and deterministically sorted.

## Decision
1. Implement `ShippingRateEngine` executing in strict stages:
   - Validate context and destination.
   - Filter physical shippable items and form package candidates.
   - Match destination zones.
   - Filter eligible methods and apply restrictions.
   - Invoke registered calculators / carrier providers.
   - Apply handling fees, carrier markups, and Promotion FreeShipping benefits.
   - Sort quotes by priority, total amount, and stable method code.
2. Quoting is strictly read-only and never purchases labels or creates persistent records.

## Consequences
- Robust, reproducible rate calculation across all channels.
