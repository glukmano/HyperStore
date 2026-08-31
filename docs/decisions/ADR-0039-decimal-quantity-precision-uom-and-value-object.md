# ADR-0039: Decimal Quantity Precision, Unit of Measure and Value Object

## Status
Accepted

## Context
E-commerce products can be sold as discrete pieces (e.g. 1 shirt) or fractional quantities (e.g. 1.2500 kg of coffee, 0.7500 meters of fabric, 2.5000 liters of oil). Binary floating-point arithmetic causes precision loss.

## Decision
1. Database Schema: All quantity columns use `NUMERIC(14, 4)` in PostgreSQL.
2. Value Object: `Quantity.php` wraps `bcmath` operations with a fixed scale of 4 decimal places.
3. Safe Construction: Binary float inputs are strictly forbidden. Creation is only permitted via `Quantity::fromString('1.2500')`, `Quantity::fromInteger(10)`, and `Quantity::zero()`.
4. Unit of Measure: Create `units_of_measure` table (`code`, `name`, `symbol`, `scale`, `status`) to represent standard units (`piece`, `kg`, `g`, `meter`, `cm`, `liter`).
5. Product/Variant stock items reference `unit_of_measure_code`.

## Consequences
- Eliminates floating-point rounding errors across all inventory calculations and transfers.
