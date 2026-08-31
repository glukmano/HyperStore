# ADR-0036: Cost and Margin Access and Security Boundary

## Status
Accepted

## Context
Product cost amount (`cost_minor`) and calculated margins are sensitive business data that must not be exposed to unauthorized store staff or public APIs.

## Decision
1. Protect `cost_minor` and margin metrics with dedicated permission `pricing.cost.view`.
2. Public and general store APIs omit cost fields completely.
3. Control Center hides cost/margin cards unless the authenticated user possesses `pricing.cost.view` or is a SuperAdmin.

## Consequences
- Safeguards vendor and platform profitability data from unauthorized exposure.
