# ADR-0016: Product Type Registry and Capability Contracts

## Status
Accepted

## Context
HyperStore supports a vast array of commerce verticals (Physical, Digital, License Keys, Subscriptions, Top-Ups, Gift Cards, Services, Bookings, Rentals, Bundles, Variable, Configurable, Custom, Affiliate, Preorder, Membership, Tickets, Auctions, RFQ, Wholesale, Made-to-Order, Print-on-Demand). Hardcoding product types into database boolean columns or closed PHP enums prevents runtime extension, third-party plugin product types, and leads to anti-patterns like `if ($product->type === ...)`.

## Decision
1. Implement an open `ProductTypeRegistry` backed by `ProductTypeInterface` and `ProductTypeDefinition`.
2. Product entities store a stable string identifier (`product_type`) referencing registered definitions.
3. Strongly typed capability methods on `ProductTypeDefinition` declare supported domain behaviors:
   - `requiresShipping(): bool`
   - `supportsInventory(): bool`
   - `supportsVariants(): bool`
   - `supportsDownloads(): bool`
   - `supportsRecurringBilling(): bool`
   - `supportsCustomerInput(): bool`
   - `supportsBooking(): bool`
   - `supportsLicenseDelivery(): bool`
   - `supportsAuction(): bool`
   - `supportsQuote(): bool`
4. Register 22 first-party Product Types at boot time via `CatalogServiceProvider`.
5. Missing or disabled plugin product types fallback safely to a `NullProductType` with all capabilities returning false and notifying administrative users.

## Consequences
- Third-party plugins and modules can register novel Product Types seamlessly.
- Product database rows remain lean, without dozens of redundant boolean flags.
- Domain services query capabilities (`$definition->requiresShipping()`) rather than matching type names.
