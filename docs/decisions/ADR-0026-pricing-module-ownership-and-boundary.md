# ADR-0026: Pricing Module Ownership and Boundary

## Status
Accepted

## Context
Catalog owns product and variant identities. Checkout and Orders own transaction state. A clear boundary is needed for monetary pricing and taxation.

## Decision
1. Create `modules/Pricing/` as an autonomous module.
2. Pricing references Catalog `product_id` and `product_variant_id` as foreign references without modifying Catalog schema.
3. Pricing does not own Cart, Checkout, Orders, Payments, or financial Ledger entries.
4. Future modules interact with Pricing through neutral DTOs (`PricingContext`, `PricingItem`, `PriceResult`, `TaxContext`, `TaxResult`).

## Consequences
- Clean separation between product structure and commercial pricing.
- Catalog remains lightweight and reusable across multi-tenant deployments.
