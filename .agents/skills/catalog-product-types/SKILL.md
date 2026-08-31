---
name: catalog-product-types
description: Enforces extensible Product Types, Attributes, Attribute Sets, Variants, and custom options. Use when working on product modeling, attributes, or variants.
---

# Catalog & Extensible Product Types

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 9, 10)

## Core Rules & Mandates

1. **Extensible Product Types**:
   - Product Types are pluggable and registered via a centralized registry implementing `ProductTypeInterface`.
   - Core architecture must support at least 20+ types: Physical, Digital Download, License Key, Subscription, Top-Up, Gift Card, Service, Booking, Rental, Bundle, Variable, Configurable, Custom, Affiliate, Preorder, Membership, Ticket, Auction, Quote/RFQ, Wholesale, Made-to-Order, Print-on-Demand.
2. **No Scattered Type Checks**:
   - Avoid scattering `if ($product->type === 'subscription')` across checkout, cart, or fulfillment modules.
   - Delegate behavior to product type drivers/strategies (validation, fields, pricing, inventory, fulfillment, storefront presentation).
3. **Rich Attribute & Variant Modeling**:
   - Support Attributes, Attribute Sets, Options, Variants, Specifications, Facets, Custom Fields, customer-entered fields, and localized labels.
   - Ensure variant combinations are generated and indexed efficiently without Cartesian explosion.

## Pre-Execution Checklist
- [ ] Is new product-type behavior implemented as a dedicated driver/strategy?
- [ ] Are attributes and facets indexed for Scout/Meilisearch filtering?
- [ ] Is digital delivery decoupled from physical shipping pipelines?

## Forbidden Shortcuts
- ❌ Hardcoding product type `switch/case` statements across unrelated modules.
- ❌ Storing attributes solely in unstructured JSON without indexing or relational constraints.
- ❌ Discarding variant SKU tracking.

## Validation Steps
1. Test registration of new custom product types.
2. Verify checkout and fulfillment hooks for distinct product types (e.g. digital vs physical).
3. Test attribute faceted search and filtering.
