# ADR-0051: Extensible Shipping Method Registry and Rate Calculator Architecture

## Status
Accepted

## Context
Shipping methods vary widely (flat rate, free shipping, weight based, table rate, local pickup, carrier calculated) and future plugins will register custom calculators.

## Decision
1. Implement `ShippingMethodTypeRegistry` defining registered method types and their associated `RateCalculatorInterface` implementations.
2. Store `rate_calculator_type` on `shipping_methods` as a string referencing the registry.
3. Forbid arbitrary public API strings or unregistered class names from executing.

## Consequences
- Core first-party methods (`flat_rate`, `free_shipping`, `table_rate`, `weight_based`, `local_pickup`, `local_delivery`, `carrier_calculated`) are cleanly registered, and future plugins can add new types without schema changes.
