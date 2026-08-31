# ADR-0064: External Carrier Failure, Timeout, and Isolation Semantics

## Status
Accepted

## Context
External carrier APIs can be slow, fail, or time out. A failure in one external provider must not break the entire rate calculation pipeline.

## Decision
1. `CarrierProviderInterface` enforces bounded timeouts (default 5s connect / 10s request).
2. When a carrier times out or errors, `ShippingRateEngine` records a structured `ProviderError` in the response warnings and continues evaluating remaining providers and static methods.
3. The overall quote request succeeds as long as at least one valid method is eligible.

## Consequences
- Resilient rate calculation that tolerates third-party outages.
