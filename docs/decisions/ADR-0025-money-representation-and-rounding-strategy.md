# ADR-0025: Money Representation and Rounding Strategy

## Status
Accepted

## Context
Commercial pricing and financial calculations require precision and zero binary floating-point rounding errors across various international currencies (e.g. 0-decimal JPY, 2-decimal USD/EUR/CHF, 3-decimal KWD).

## Decision
1. Never use PHP `float` or SQL `FLOAT`/`DOUBLE` for monetary values.
2. Store all monetary amounts as `bigint` minor units (e.g. cents, fils) in PostgreSQL alongside an ISO 4217 uppercase 3-letter currency code (e.g., `USD`, `EUR`, `CHF`, `JPY`, `KWD`).
3. Leverage `brick/money:^0.14.2` with `RoundingMode::HALF_UP` for exact decimal scaling and arithmetic.
4. Wrap all money operations in an immutable `MoneyValue` object.

## Consequences
- Zero floating-point drift or rounding anomalies in multi-currency transactions.
- Fully supports fractional cents during intermediate rate conversions before rounding to minor units upon persistence.
