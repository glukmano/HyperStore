# ADR-0021: Scoped SKU and Slug Uniqueness Strategy

## Status
Accepted

## Context
Global uniqueness constraints on SKUs and public URL slugs break multi-tenancy and multi-store requirements. Tenant A and Tenant B might legitimately sell items with manufacturer SKU "IPHONE15-128-BLK". Additionally, Store A may require German URL slugs (`/produkt/fernseher`) while Store B uses English (`/product/television`).

## Decision
1. **SKU Uniqueness Scope**:
   - Canonical `Product` SKU is unique per Tenant: `UNIQUE (tenant_id, sku)`.
   - `ProductVariant` SKU is unique per Tenant (via parent product tenant relationship).
2. **Slug Uniqueness Scope**:
   - Store Product URL Slugs are stored in `product_store_listing_translations` and are unique per `(listing_id -> store_id, locale, slug)`.
   - Category Slugs are unique per `(tenant_id, locale, slug)`.
   - Brand Slugs are unique per `(tenant_id, locale, slug)`.
3. System-reserved slugs (`admin`, `api`, `cart`, `checkout`, etc.) are blocked at validation.

## Consequences
- Tenants operate in complete isolation without SKU collision.
- Localized multi-store storefront routing is fully decoupled and deterministic.
