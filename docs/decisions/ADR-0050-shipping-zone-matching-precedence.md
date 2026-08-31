# ADR-0050: Shipping Zone Matching Engine and Specificity Precedence

## Status
Accepted

## Context
Multiple shipping zones can match a single customer delivery destination. A deterministic matching algorithm is required to resolve the most specific zone without relying on database insertion order.

## Decision
Implement `ShippingZoneMatcher` using a deterministic specificity scoring hierarchy:
1. **Explicit Exclusion Rules**: Destination matches an excluded country, region, or postal pattern -> zone is excluded.
2. **Postal Code Exact Match** (Highest specificity).
3. **Postal Code Prefix / Range Match**.
4. **Administrative Area / State / Region Match**.
5. **Country Code Match**.
6. **Broad / Global Catch-all** (Lowest specificity).

Ties are broken deterministically by configured priority (higher integer first), followed by stable zone code/ID.

## Consequences
- Guaranteed deterministic zone matching across all stores, markets, and channels.
