# ADR-0015: Catalog Module Ownership and Boundaries

## Status
Accepted

## Context
Conforming to PROJECT_MASTER_PLAN.md Section 14 and Phase 03 requirements, the platform requires a dedicated, decoupled domain module for managing products, categories, brands, specifications, variants, and store publication bindings. Previous monolithic e-commerce designs often blur lines between catalog definitions, inventory stock balances, dynamic pricing calculations, checkout carts, and fulfillment pipelines, creating brittle dependency cycles and preventing modular evolution.

## Decision
1. Create `modules/Catalog/` as the canonical owner of product catalog definitions.
2. Catalog strictly owns:
   - Canonical Product entities and translatable content
   - Product Type Registry and capability definitions
   - Categories, Brands, and hierarchical taxonomies
   - Specification Attributes, Attribute Sets, and typed attribute values
   - Purchasable Variants and option combinations
   - Store Publication and Market/Channel availability matrices
   - Product customizable customer input field definitions
   - Bundle and composite product relationship links
3. Catalog strictly does NOT own:
   - Physical warehouse inventory quantities or allocations (owned by future Inventory module)
   - Dynamic price calculation engines, tax rules, or discounts (owned by future Pricing/Tax modules)
   - Cart, checkout, orders, or payment transactions (owned by future Checkout/Order/Payment modules)
   - Vendor commissions, seller payouts, or marketplace moderation workflows (owned by future Marketplace module)
   - Fulfillment, shipping carrier integrations, or delivery tracking (owned by future Fulfillment/Shipping modules)
4. Catalog provides domain events (e.g. `ProductCreated`, `ProductUpdated`, `ProductArchived`, `ProductPublishedToStore`, `VariantCreated`, `CategoryCreated`) and service contracts for downstream modules to subscribe without tight coupling.

## Consequences
- Clean architectural boundaries compliant with Modular Monolith invariants.
- Downstream modules interact via explicit contracts and events.
- Zero premature entanglement with pricing, inventory, or order domains.
