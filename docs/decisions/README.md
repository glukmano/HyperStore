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

### Phase 05: Inventory, Warehouses & Multi-Source Stock
- [ADR-0037: Inventory Module Ownership and Boundaries](ADR-0037-inventory-module-ownership-and-boundaries.md)
- [ADR-0038: Warehouse vs Inventory Source Architecture](ADR-0038-warehouse-vs-inventory-source-architecture.md)
- [ADR-0039: Decimal Quantity Precision, Unit of Measure and Value Object](ADR-0039-decimal-quantity-precision-uom-and-value-object.md)
- [ADR-0040: Stock Balance, Condition Buckets and Movement Ledger Model](ADR-0040-stock-balance-condition-buckets-and-movement-ledger-model.md)
- [ADR-0041: Concurrency Safety and Oversell Protection via Deterministic Row Locking](ADR-0041-concurrency-safety-and-oversell-protection-via-deterministic-row-locking.md)
- [ADR-0042: Inventory Reservation Lifecycle and Split Multi-Source Allocation](ADR-0042-inventory-reservation-lifecycle-and-split-multi-source-allocation.md)
- [ADR-0043: Multi-Source Stock Aggregation and Allocation Routing Foundation](ADR-0043-multi-source-stock-aggregation-and-allocation-routing-foundation.md)
- [ADR-0044: Backorder and Preorder Inventory Policies](ADR-0044-backorder-and-preorder-inventory-policies.md)
- [ADR-0045: Inter-Warehouse Transfer Workflow and Accounting](ADR-0045-inter-warehouse-transfer-workflow-and-accounting.md)
- [ADR-0046: Inventory Idempotency Persistence Strategy](ADR-0046-inventory-idempotency-persistence-strategy.md)
- [ADR-0047: Inventory Reconciliation and Reservation Integrity Strategy](ADR-0047-inventory-reconciliation-and-reservation-integrity-strategy.md)
- [ADR-0048: External Supplier and Vendor Stock Extension Boundaries](ADR-0048-external-supplier-and-vendor-stock-extension-boundaries.md)

### Phase 08: Orders, Order Lifecycle & State Machine Foundation
- [ADR-0083: Order Module Ownership, Core Aggregates & Boundary Separation](ADR-0083-order-module-ownership-core-aggregates-and-boundary-separation.md)
- [ADR-0084: Immutable CheckoutReadyResult Handoff & Commercial Snapshotting](ADR-0084-immutable-checkoutreadyresult-handoff-and-commercial-snapshotting.md)
- [ADR-0085: Tenant/Date-Scoped Atomic Order Number Generation Strategy](ADR-0085-tenant-date-scoped-atomic-order-number-generation-strategy.md)
- [ADR-0086: Three-Dimensional State Machines and Status Ownership Boundaries](ADR-0086-three-dimensional-state-machines-and-status-ownership-boundaries.md)
- [ADR-0087: Order Item Snapshot Immutability & Historical Independence from Catalog](ADR-0087-order-item-snapshot-immutability-and-historical-independence-from-catalog.md)
- [ADR-0088: Retained Inventory Reservation Lifecycle & Cancellation Release Contract](ADR-0088-retained-inventory-reservation-lifecycle-and-cancellation-release-contract.md)
- [ADR-0089: Aggregate-Scoped Order Idempotency & PostgreSQL Concurrency Invariants](ADR-0089-aggregate-scoped-order-idempotency-and-postgresql-concurrency-invariants.md)
- [ADR-0090: Customer & Guest Order Ownership, Ephemeral Token Return & IDOR Defense](ADR-0090-customer-and-guest-order-ownership-ephemeral-token-return-and-idor-defense.md)
- [ADR-0091: Order Status History, Audit Trail & Typed Domain Events](ADR-0091-order-status-history-audit-trail-and-typed-domain-events.md)
- [ADR-0092: Deterministic Cart Discount Allocation for Taxable Line Snapshots](ADR-0092-deterministic-cart-discount-allocation-for-taxable-line-snapshots.md)

### Phase 09: Payments & Payment Orchestration Foundation
- [ADR-0093: Payment Bounded Context Ownership, Unidirectional Order Decoupling & Event Synchronization](ADR-0093-payment-bounded-context-ownership-and-order-decoupling.md)
- [ADR-0094: Provider-Neutral Gateway & Transaction Attempt Architecture](ADR-0094-provider-neutral-gateway-and-transaction-attempt-architecture.md)
- [ADR-0095: Two-Phase Remote Gateway Consistency, UNKNOWN State & Out-of-Band Reconciliation](ADR-0095-two-phase-remote-gateway-consistency-and-unknown-state.md)
- [ADR-0096: Payment Aggregate Obligation & PaymentTransaction Attempt State Machines](ADR-0096-payment-aggregate-and-transaction-state-machines.md)
- [ADR-0097: Aggregate-Scoped Payment Idempotency & Provider Idempotency Derivation](ADR-0097-aggregate-scoped-payment-idempotency-and-provider-keys.md)
- [ADR-0098: Zero-Total Order Internal Settlement Policy](ADR-0098-zero-total-order-internal-settlement-policy.md)
- ADR-0099: *(Deferred: Payment Webhook Verification & Asynchronous Ingestion)*
- [ADR-0100: PCI Boundary & Sensitive Payment Data Isolation](ADR-0100-pci-boundary-and-sensitive-payment-data-isolation.md)

### Phase 10: Ledger / Financial Accounting Foundation
- [ADR-0101: Ledger Bounded Context Ownership, Money-Movement Boundary & Deferred Revenue Recognition](ADR-0101-ledger-bounded-context-ownership-money-movement-and-deferred-revenue-recognition.md)
- [ADR-0102: Double-Entry General Ledger, Append-Only Journals & PostgreSQL Immutability](ADR-0102-double-entry-general-ledger-append-only-journals-and-postgresql-immutability.md)
- [ADR-0103: Transaction-Scoped Source Idempotency, Tenant Integrity & Reversal References](ADR-0103-transaction-scoped-source-idempotency-tenant-integrity-and-reversal-references.md)
- [ADR-0104: Administrative Journal Reversal vs Commercial Refund Money Movement](ADR-0104-administrative-journal-reversal-vs-commercial-refund-money-movement.md)
- [ADR-0105: Single-Currency Journal Balancing & Query-Time Balance Derivation](ADR-0105-single-currency-journal-balancing-and-query-time-balance-derivation.md)
- [ADR-0106: Payment Event Snapshot Adapter, Eventual Consistency & Missing-Posting Recovery](ADR-0106-payment-event-snapshot-adapter-eventual-consistency-and-missing-posting-recovery.md)
