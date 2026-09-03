# ADR-0107: Marketplace Boundaries, Canonical Product Separation & Vendor Listing

## Status
ACCEPTED

## Date
2026-09-03

## Context
Section 11 of `PROJECT_MASTER_PLAN.md` mandates multi-vendor marketplace support. In HyperStore, `modules/Catalog` owns canonical products, variants, attributes, and categories. A canonical product (e.g. a specific consumer electronics device or book) can be offered by multiple competing vendors across multiple stores. Adding `Product.vendor_id` would permanently break multi-vendor catalog capability by binding a canonical product to a single vendor.

## Decision
1. **Catalog Boundary & Multi-Vendor Invariant**: `Product.vendor_id` is strictly prohibited on canonical products. Catalog maintains ownership of canonical definitions without vendor coupling.
2. **VendorListing Model**: Marketplace introduces `vendor_listings`, representing a vendor's commercial offer of a canonical product or variant (`vendor_id`, `product_id`, `product_variant_id`, `vendor_sku`, `status`).
3. **PostgreSQL Nullable-Variant Uniqueness**: In PostgreSQL, NULL values are treated as distinct. To prevent duplicate listings, two partial unique indexes are enforced:
   - `CREATE UNIQUE INDEX uq_vendor_listings_product ON vendor_listings (tenant_id, vendor_id, product_id) WHERE product_variant_id IS NULL;`
   - `CREATE UNIQUE INDEX uq_vendor_listings_variant ON vendor_listings (tenant_id, vendor_id, product_id, product_variant_id) WHERE product_variant_id IS NOT NULL;`
4. **Inventory Separation**: `vendor_listings` owns commercial offers only; stock quantities remain authoritative within `modules/Inventory`.

## Consequences
- Canonical products can be freely multi-sourced across vendors without data duplication.
- Database partial unique indexes guarantee strict multi-tenant listing uniqueness at the engine level.
