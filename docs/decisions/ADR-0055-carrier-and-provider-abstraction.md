# ADR-0055: Carrier, Carrier Service, and Normalized Provider Abstraction

## Status
Accepted

## Context
Carriers (e.g. DHL, Swiss Post, UPS) provide carrier-calculated rates and tracking. Direct coupling to specific carrier APIs must be avoided.

## Decision
1. Create `CarrierRegistry` and `CarrierProviderInterface`.
2. Carrier providers return normalized domain DTOs (`CarrierRateResult`, `CarrierServiceDTO`, `TrackingResult`, `CreateLabelResult`, `ProviderError`).
3. Implement `ManualCarrierProvider` as the first-party static/offline carrier provider.
4. External provider structures never leak into core shipping or UI layers.

## Consequences
- Shipping domain remains agnostic of external carrier SDK details.
