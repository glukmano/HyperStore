# ADR-0034: Fixed-Discount Multi-Currency Strategy

## Status
Accepted

## Context
A fixed discount (e.g. 10 units off) cannot be applied identically across currencies (e.g. 10 USD != 10 KWD).

## Decision
1. Fixed discounts require either:
   - Specific currency amount definitions in the promotion action configuration; OR
   - Currency conversion via `CurrencyConversionService` from the promotion's base currency into the target cart currency.
2. Percentage discounts remain naturally currency-neutral.

## Consequences
- Prevents catastrophic loss from mismatched fixed monetary discounts across disparate currencies.
