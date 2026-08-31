# ADR-0033: Promotion Stacking and Priority Behavior

## Status
Accepted

## Context
Multiple promotions may be active simultaneously. A clear evaluation order, exclusivity rule, and stacking boundary are required.

## Decision
1. Promotions are evaluated in descending order of `priority` (highest integer first).
2. If an `is_exclusive` promotion applies, no further promotions are evaluated.
3. If `stop_further_rules` is true, evaluation halts after applying the current promotion.
4. Non-exclusive, stackable promotions apply sequentially to the running total.

## Consequences
- Eliminates discount loops and unexpected compounding discounts.
