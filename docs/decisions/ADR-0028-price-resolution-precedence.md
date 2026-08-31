# ADR-0028: Price Resolution Precedence

## Status
Accepted

## Context
Multiple matching prices may exist for a given product/variant in a specific shopping context. A deterministic resolution hierarchy is required.

## Decision
The `PriceResolver` resolves prices in the following strict order of precedence:
1. **Active Scheduled Sale Price** on matching Variant/Product
2. **Variant Price** in the most specific matching Price Book (Customer Group + Store/Market)
3. **Quantity Tier Break** (if requested quantity >= min_quantity)
4. **Canonical Product Price** in matching Price Book
5. **Fallback to Base Price Book** for requested currency / market
6. **Diagnostic Trace**: Every resolution generates a structured explanation log.

## Consequences
- Deterministic, testable, and explainable pricing outcomes for Admin, API, and future AI agents.
