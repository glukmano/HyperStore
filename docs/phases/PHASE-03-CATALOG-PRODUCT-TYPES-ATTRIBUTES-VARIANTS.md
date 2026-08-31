# Phase 03 Specification: Catalog, Product Types, Attributes & Variants

## Phase Information
- **Phase ID**: `PHASE-03`
- **Phase Name**: `Catalog, Product Types, Attributes & Variants`
- **Status**: `ACTIVE`
- **Specification Date**: 2026-08-31
- **Governing Document**: [PROJECT_MASTER_PLAN.md](file:///Applications/MAMP/htdocs/HyperStore/PROJECT_MASTER_PLAN.md) Section 14, 15, 28, 35

---

## 1. Architectural Mandates & Invariants

1. **Catalog Module Ownership & Boundary** ([ADR-0015](file:///Applications/MAMP/htdocs/HyperStore/docs/decisions/ADR-0015-catalog-module-ownership-and-boundaries.md)):
   - The first full production module is created at `modules/Catalog/`.
   - Catalog owns canonical product definitions, categories, brands, specifications (attributes), option combinations (variants), and store publication bindings.
   - Catalog strictly does **NOT** own: inventory quantities, warehouses, pricing engines, checkout, orders, payments, vendor commissions, shipping, or fulfillment.

2. **Module-Owned UI & API Routes**:
   - Catalog Livewire components live in `modules/Catalog/Livewire/` and render views from `modules/Catalog/Resources/views/`.
   - Catalog API routes live in `modules/Catalog/Routes/api.php` and web routes in `modules/Catalog/Routes/web.php`.
   - The root application Control Center provides only the outer shell.

3. **Product Type Registry & Capability System** ([ADR-0016](file:///Applications/MAMP/htdocs/HyperStore/docs/decisions/ADR-0016-product-type-registry-and-capability-contracts.md)):
   - Open, extensible registry pattern (`ProductTypeRegistry`) providing `ProductTypeDefinition` objects.
   - Type capabilities (`requiresShipping`, `supportsInventory`, `supportsVariants`, `supportsDownloads`, `supportsCustomerInput`, `supportsRecurringBilling`, `supportsBooking`, etc.) are declared in strongly typed code objects—never as duplicated boolean columns in the `products` table.
   - 22 first-party Product Types registered at boot: Physical, Digital, License, Subscription, TopUp, GiftCard, Service, Booking, Rental, Bundle, Variable, Configurable, Custom, Affiliate, Preorder, Membership, Ticket, Auction, Rfq, Wholesale, MadeToOrder, PrintOnDemand.

4. **Hybrid Typed-Value Attribute Storage & Relational Multiselect** ([ADR-0017](file:///Applications/MAMP/htdocs/HyperStore/docs/decisions/ADR-0017-hybrid-typed-attribute-storage-architecture.md)):
   - Reusable specification attributes with typed relational storage (`text_value`, `int_value`, `decimal_value`, `boolean_value`, `date_value`, `datetime_value`, `file_path`).
   - `select` and `multiselect` options stored relationally via `product_attribute_options` for indexed SQL queries (`WHERE attribute_id = X AND attribute_option_id IN (...)`).
   - Attribute requiredness and grouping belong to `attribute_set_attributes` in context.

5. **Strict Product vs Variant vs Customer Input Distinction** ([ADR-0018](file:///Applications/MAMP/htdocs/HyperStore/docs/decisions/ADR-0018-product-vs-variant-vs-customer-input-distinction.md)):
   - *Attributes*: Product specifications / search / filter metadata.
   - *Variant Options*: Dimension combinations creating purchasable SKUs.
   - *Customer Inputs*: Customizable fields (`product_custom_fields`) with typed options (`product_custom_field_options`) supplied by the buyer.

6. **Localized Content & Normalized Slug Ownership** ([ADR-0019](file:///Applications/MAMP/htdocs/HyperStore/docs/decisions/ADR-0019-catalog-multi-language-localization-strategy.md), [ADR-0021](file:///Applications/MAMP/htdocs/HyperStore/docs/decisions/ADR-0021-scoped-sku-and-slug-uniqueness-strategy.md)):
   - No hardcoded `name_en` / `name_ar` columns.
   - Technical identifiers (`sku`, `code`) are stored on parent tables.
   - Public localized Store URL slugs and content live in `product_store_listing_translations`, allowing `Store A + DE: /produkt/fernseher` vs `Store A + AR: /product/تلفاز`.
   - Category slugs live in `category_translations`, Brand slugs in `brand_translations`.

7. **Canonical Multi-Store Publication & Availability Matrix** ([ADR-0020](file:///Applications/MAMP/htdocs/HyperStore/docs/decisions/ADR-0020-canonical-product-multi-store-publication-strategy.md)):
   - A single canonical `Product` row is published to multiple Stores via `product_store_listings`.
   - Store listings map availability to specific `Market` and `Channel` entities with strict tenant-isolation validation.

8. **Order-Independent Variant Combination Uniqueness**:
   - `Color=Red + Size=M` is identical to `Size=M + Color=Red`. Guaranteed via normalized combination hashing and database constraints.

9. **Lifecycle-Aware Removal**:
   - Product deletion through API/UI performs archiving (`status = 'archived'`), preserving referential integrity for future orders/ledger.

10. **Category Hierarchy Cycle Prevention** ([ADR-0023](file:///Applications/MAMP/htdocs/HyperStore/docs/decisions/ADR-0023-hierarchical-category-cycle-prevention-strategy.md)):
    - Adjacency list (`parent_id`) with automated cyclic ancestry detection.

11. **Catalog Media Abstraction** ([ADR-0022](file:///Applications/MAMP/htdocs/HyperStore/docs/decisions/ADR-0022-catalog-media-abstraction-and-storage.md)):
    - Backed by `spatie/laravel-medialibrary` behind Catalog domain abstractions with distinct collections: `product_gallery`, `product_thumbnail`, `variant_gallery`, `category_image`, `brand_logo`.

---

## 2. Scope Checklist

- [ ] Create 10 Architectural Decision Records (`ADR-0015` to `ADR-0024`).
- [ ] Create PostgreSQL database migrations with relational constraints, foreign keys, and indexes.
- [ ] Install & register `spatie/laravel-medialibrary` in `docs/DEPENDENCIES.md`.
- [ ] Build `modules/Catalog/` with Module Manifest, ServiceProvider, Contracts, DTOs, Models, Product Types, Action Services, Livewire components, Views, and API Routes.
- [ ] Register 22 first-party Product Types with capability definitions.
- [ ] Build focused application actions (`CreateProductAction`, `UpdateProductAction`, `ArchiveProductAction`, `PublishProductToStoreAction`, `CreateVariantAction`, `AssignAttributeValuesAction`, `CreateCategoryAction`).
- [ ] Build module-owned Livewire Control Center management screens with full RTL/LTR logical CSS.
- [ ] Author comprehensive Pest test suites covering isolation, cycles, variants, attributes, publication, localization, RBAC, auditing, and architecture invariants.
- [ ] Verify static analysis (Larastan Level 8), Pint formatting, asset builds, and security audits.
- [ ] Commit to git and generate Phase 03 Completion Report.
