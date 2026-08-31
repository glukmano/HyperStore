# ADR-0031: Tax Architecture and Inclusive/Exclusive Strategy

## Status
Accepted

## Context
Global commerce requires flexible taxation (VAT, GST, sales tax) with both tax-inclusive (common in EU/Middle East) and tax-exclusive (common in US/B2B) pricing.

## Decision
1. Define `TaxClass` (`Standard`, `Reduced`, `Zero`, `Exempt`, `Digital`), `TaxZone` (Country/Region/Market), and `TaxRate`.
2. `TaxCalculator` supports both `tax_inclusive` and `tax_exclusive` calculation modes:
   - **Exclusive**: `tax = net * (rate / 100)`; `gross = net + tax`
   - **Inclusive**: `tax = gross - (gross / (1 + rate / 100))`; `net = gross - tax`
3. Minor-unit rounding is performed once at the line level to prevent multi-item rounding drift.

## Consequences
- Clean tax determination supporting international jurisdiction requirements without ledger entanglement.
