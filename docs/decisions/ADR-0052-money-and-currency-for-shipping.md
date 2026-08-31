# ADR-0052: Money and Multi-Currency Handling for Shipping Rates and Markups

## Status
Accepted

## Context
Shipping costs, markups, handling fees, and discounts must adhere to strict financial precision without floating-point drift.

## Decision
1. All shipping rates and fees are represented using `MoneyValue` / integer minor units with ISO-4217 currency codes.
2. Static/table rates can be defined in explicit currency amounts or in a base currency.
3. When checkout currency differs from base currency, conversions strictly invoke `CurrencyConversionInterface` from `modules/Pricing/`.

## Consequences
- Zero floating-point arithmetic in shipping rate calculations.
