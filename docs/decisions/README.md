# Architectural Decision Records

This directory contains all ADRs (Architectural Decision Records) for the HyperStore platform.

Each ADR is an immutable record of an architectural decision. To supersede an ADR, create a new one referencing the old one and update the old one's status to "Superseded by ADR-XXXX".

## Index

| ID | Title | Status | Phase |
|---|---|---|---|
| [ADR-0001](ADR-0001-modular-monolith-architecture.md) | Modular Monolith Architecture | ✅ Accepted | PHASE-01 |
| [ADR-0002](ADR-0002-postgresql-source-of-truth.md) | PostgreSQL as Primary Database Source of Truth | ✅ Accepted | PHASE-01 |
| [ADR-0003](ADR-0003-project-owned-module-kernel.md) | Project-Owned Module Kernel (No Third-Party Module Framework) | ✅ Accepted | PHASE-01 |
| [ADR-0004](ADR-0004-strict-no-float-money.md) | Strict No-Float Money — Integer Minor Units Only | ✅ Accepted | PHASE-01 |
| [ADR-0005](ADR-0005-canonical-multi-store-products.md) | Canonical Multi-Store Product Catalog | ✅ Accepted | PHASE-01 |
| [ADR-0006](ADR-0006-theme-and-plugin-isolation.md) | Theme and Plugin Isolation | ✅ Accepted | PHASE-01 |

## Template

Use [docs/templates/ADR-TEMPLATE.md](../templates/ADR-TEMPLATE.md) for new ADRs.

## Process

1. Draft the ADR in a feature branch.
2. Get review from at least one architect or senior engineer.
3. Merge — the ADR is now "Accepted".
4. ADRs are NEVER retroactively edited once accepted (except to add a "Superseded" status).
| [ADR-0015](ADR-0015-catalog-module-ownership-and-boundaries.md) | Catalog Module Ownership and Boundaries | ACCEPTED | 2026-08-31 |
| [ADR-0016](ADR-0016-product-type-registry-and-capability-contracts.md) | Product Type Registry and Capability Contracts | ACCEPTED | 2026-08-31 |
| [ADR-0017](ADR-0017-hybrid-typed-attribute-storage-architecture.md) | Hybrid Typed-Value Attribute Storage Architecture | ACCEPTED | 2026-08-31 |
| [ADR-0018](ADR-0018-product-vs-variant-vs-customer-input-distinction.md) | Product vs Variant vs Customer Input Distinction | ACCEPTED | 2026-08-31 |
| [ADR-0019](ADR-0019-catalog-multi-language-localization-strategy.md) | Catalog Multi-Language Localization Strategy | ACCEPTED | 2026-08-31 |
| [ADR-0020](ADR-0020-canonical-product-multi-store-publication-strategy.md) | Canonical Product Multi-Store Publication Strategy | ACCEPTED | 2026-08-31 |
| [ADR-0021](ADR-0021-scoped-sku-and-slug-uniqueness-strategy.md) | Scoped SKU and Slug Uniqueness Strategy | ACCEPTED | 2026-08-31 |
| [ADR-0022](ADR-0022-catalog-media-abstraction-and-storage.md) | Catalog Media Abstraction and Storage | ACCEPTED | 2026-08-31 |
| [ADR-0023](ADR-0023-hierarchical-category-cycle-prevention-strategy.md) | Hierarchical Category Cycle Prevention Strategy | ACCEPTED | 2026-08-31 |
| [ADR-0024](ADR-0024-catalog-lifecycle-and-archive-strategy.md) | Catalog Lifecycle and Archive Strategy | ACCEPTED | 2026-08-31 |
| [`ADR-0025`](ADR-0025-money-representation-and-rounding-strategy.md) | Money Representation and Rounding Strategy | Accepted |
| [`ADR-0026`](ADR-0026-pricing-module-ownership-and-boundary.md) | Pricing Module Ownership and Boundary | Accepted |
| [`ADR-0027`](ADR-0027-price-book-architecture.md) | Price Book Architecture | Accepted |
| [`ADR-0028`](ADR-0028-price-resolution-precedence.md) | Price Resolution Precedence | Accepted |
| [`ADR-0029`](ADR-0029-multi-currency-exchange-rate-abstraction.md) | Multi-Currency Exchange-Rate Abstraction | Accepted |
| [`ADR-0030`](ADR-0030-customer-group-tier-wholesale-pricing-model.md) | Customer Group, Tier, and Wholesale Pricing Model | Accepted |
| [`ADR-0031`](ADR-0031-tax-architecture-and-inclusive-exclusive-strategy.md) | Tax Architecture and Inclusive/Exclusive Strategy | Accepted |
| [`ADR-0032`](ADR-0032-promotion-rule-engine-registry-design.md) | Promotion Rule Engine Registry Design | Accepted |
| [`ADR-0033`](ADR-0033-promotion-stacking-priority-behavior.md) | Promotion Stacking and Priority Behavior | Accepted |
| [`ADR-0034`](ADR-0034-fixed-discount-multi-currency-strategy.md) | Fixed-Discount Multi-Currency Strategy | Accepted |
| [`ADR-0035`](ADR-0035-coupon-uniqueness-and-usage-boundary.md) | Coupon Uniqueness and Usage Boundary | Accepted |
| [`ADR-0036`](ADR-0036-cost-margin-access-and-security-boundary.md) | Cost and Margin Access and Security Boundary | Accepted |
