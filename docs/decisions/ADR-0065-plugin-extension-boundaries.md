# ADR-0065: Provider and Method Extension Boundaries for Future Plugins

## Status
Accepted

## Context
Third-party plugins must be able to register custom carrier providers, shipping method calculators, and packing strategies.

## Decision
1. Provide extension registration hooks in `ShippingMethodTypeRegistry`, `CarrierRegistry`, and `PackingStrategyRegistry`.
2. Plugins implement standard contracts (`CarrierProviderInterface`, `RateCalculatorInterface`, `PackingStrategyInterface`).
3. Core domain dynamically resolves registered providers by unique code.

## Consequences
- Fully extensible shipping infrastructure ready for future plugins.
