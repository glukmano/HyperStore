# ADR-0061: Promotion FreeShipping Structured Benefit Integration

## Status
Accepted

## Context
PHASE-04 Promotions can emit a `FreeShippingBenefit`. Shipping must apply this benefit without coupling to promotion internals.

## Decision
1. `ShippingRateEngine` accepts optional `PromotionBenefit` collection on the quote request.
2. When an eligible `FreeShippingBenefit` is present, the eligible shipping method's rate is discounted to zero, preserving original amount and benefit metadata in `RateBreakdown`.
3. Promotion free shipping cannot bypass zone exclusions, method inactive status, or physical shipping restrictions.

## Consequences
- Seamless promotion discount integration while preserving shipping integrity.
