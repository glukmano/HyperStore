# ADR-0024: Catalog Lifecycle and Archive Strategy

## Status
Accepted

## Context
Deleting catalog products that have historical references in past customer carts, orders, invoices, or ledger audit entries causes data corruption and breaks legal compliance.

## Decision
1. Adopt an explicit single lifecycle status enum across Catalog models:
   - `Product`: `draft`, `active`, `inactive`, `archived`
   - `ProductVariant`: `active`, `inactive`, `archived`
   - `Category` / `Brand`: `active`, `inactive`, `archived`
2. Removal operations through API (`DELETE /api/v1/catalog/products/{id}`) and Control Center perform a non-destructive lifecycle archive (`status = 'archived'`).
3. Archived products are excluded from storefront catalog listings while preserving historical referential integrity.
4. Hard database deletion is strictly restricted to draft products that possess zero store publications and zero historical references.

## Consequences
- Zero referential integrity breakage for future order and ledger phases.
- Transparent auditing and lifecycle traceability.
