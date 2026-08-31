# ADR-0005: Canonical Multi-Store Product Catalog

| Field        | Value                                |
|-------------|--------------------------------------|
| ID          | ADR-0005                             |
| Status      | Accepted                             |
| Date        | 2026-08-31                           |
| Deciders    | Project Lead, Platform Architect     |
| Phase       | PHASE-01 (ADR only — implementation deferred to Catalog Phase) |

## Context

HyperStore supports multiple stores (and potentially multiple tenants). Each store may
sell products at different prices, with different visibility, different inventory levels,
and translated in different languages.

We needed to decide: one product record per store, or a single canonical product with
per-store overrides?

## Decision

We adopt a **single canonical product** model with per-store listing overrides.

### Model hierarchy (to be implemented in Catalog Phase):

```
Product (canonical)
  └── ProductListing  (per Store — visibility, store price override, sort order)
       └── ProductVariant (per SKU — size, color, etc.)
            └── VariantInventory (per warehouse/location)
            └── VariantPrice     (per Store × Currency)
```

### Key rules:

1. A `Product` exists once in the database with canonical content (title, description, attributes).
2. `ProductListing` links a `Product` to a `Store` and controls store-specific visibility and ordering.
3. Per-store pricing lives in `VariantPrice` keyed on `(variant_id, store_id, currency_code)`.
4. Store-specific translations live in a `product_translations` table, not on the product itself.
5. A product is only visible in a store if an active `ProductListing` record exists for that store.
6. Deleting a `ProductListing` does not delete the canonical `Product`.

## Rationale

| Approach | Pros | Cons |
|---|---|---|
| One product per store | Simple queries | Data duplication, sync nightmare |
| Canonical + store listings ✅ | Single source of truth, clean overrides | Slightly more complex joins |

The canonical approach prevents inventory/pricing divergence across stores and enables
marketplace functionality (one product, many vendor listings).

## Consequences

- The Catalog Module must enforce that all storefront product queries always join through `ProductListing`.
- Vendor marketplace listings are a separate `VendorListing` layer on top of `ProductListing`.
- Phase 01 creates no product tables — this ADR only records the architectural decision.

## References

- PROJECT_MASTER_PLAN.md §Catalog Architecture
- ADR-0001 (Modular Monolith)
- ADR-0002 (PostgreSQL)
