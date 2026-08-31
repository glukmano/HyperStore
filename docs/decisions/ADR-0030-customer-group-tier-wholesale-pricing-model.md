# ADR-0030: Customer Group, Tier, and Wholesale Pricing Model

## Status
Accepted

## Context
B2B, VIP, and Wholesale channels require quantity volume breaks (tier pricing) and customer group-specific price discounts.

## Decision
1. Wholesale pricing is modeled through a combination of **Customer Group Price Books** and **Tier Prices** (`tier_prices`).
2. `tier_prices` defines `min_quantity`, `max_quantity`, and `amount_minor`.
3. Overlapping tiers on the same price book are prevented by validation logic and unique ranges.

## Consequences
- Unifies retail and wholesale pricing under one extensible, maintainable architecture.
