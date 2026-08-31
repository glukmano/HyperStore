# PHASE-06 — SHIPPING, FULFILLMENT, ZONES & CARRIERS
## Authoritative Engineering Specification (Updated & Approved)

**Status**: `APPROVED & IN EXECUTION`  
**Phase Objective**: Build the complete first-party shipping and fulfillment foundation for Hyper Commerce.

---

## 1. Domain & Module Architecture

### 1.1 Explicit Module Boundaries & Dependency Flow
- **`modules/Shipping/`**:
  - Depends only on: Platform Contexts, `modules/Catalog/` capability contracts, `modules/Pricing/` money/currency contracts.
  - Owns: Shipping Zones, Shipping Methods, Method Type Registry, Rate Calculation Engine (`ShippingRateEngine`), Package Types, Weight & Dimension Value Objects, Carrier Abstraction (`CarrierProviderInterface`, `CarrierRegistry`), Encrypted Carrier Credentials, Delivery Estimates, Shipping Restrictions, Local Pickup & Delivery methods.
- **`modules/Fulfillment/`**:
  - Depends only on: `modules/Inventory/` contracts, `modules/Shipping/` contracts, `modules/Catalog/` capability contracts.
  - Owns: Fulfillment Planning Foundation (`FulfillmentPlan`, `FulfillmentGroup`), multi-source fulfillment eligibility & allocation, split fulfillment planning, packing strategy (`PackingStrategyInterface`).
- **Invariants**:
  - `modules/Inventory/` MUST NOT depend on Shipping or Fulfillment.
  - `modules/Catalog/` MUST NOT depend on Shipping or Fulfillment.
  - `modules/Pricing/` MUST NOT depend on Shipping implementation.
  - Rate quoting and fulfillment planning are 100% PURE / READ-ONLY: zero stock mutations, zero reservations, zero label purchases, zero Order/Cart creation.

---

## 2. ADR Architecture Roadmap (ADR-0049 to ADR-0065)

- **ADR-0049**: Shipping vs Fulfillment Domain Ownership and Module Boundaries.
- **ADR-0050**: Shipping Zone Matching Engine and Specificity Precedence (Exclusion > Postal Exact > Postal Prefix/Range > Region > Country > Broad Global).
- **ADR-0051**: Extensible Shipping Method Registry and Rate Calculator Architecture.
- **ADR-0052**: Money and Multi-Currency Handling for Shipping Rates and Markups.
- **ADR-0053**: Weight and Dimension Exact Decimal Precision (Scale 4) and Unit Semantics.
- **ADR-0054**: Package Candidate Modeling and Strategy-Based Packing Architecture.
- **ADR-0055**: Carrier, Carrier Service, and Normalized Provider Abstraction.
- **ADR-0056**: Carrier Credential Security, Encryption at Rest, and Strict Secret Redaction.
- **ADR-0057**: Fulfillment Plan and Deterministic Multi-Source Split Strategy.
- **ADR-0058**: InventorySource Integration Boundary and Pure Fulfillment Eligibility.
- **ADR-0059**: Shipping Rate Calculation Pipeline and Deterministic Ordering.
- **ADR-0060**: Commercial Table-Rate Rule Condition/Action Typed Architecture.
- **ADR-0061**: Promotion FreeShipping Structured Benefit Integration.
- **ADR-0062**: Local Pickup and Local Delivery Commercial Domain Models.
- **ADR-0063**: Transient ShipmentPlan / FulfillmentPlan Decision (No Premature Persistent Shipment Model).
- **ADR-0064**: External Carrier Timeout, Failure Isolation, and Partial Result Semantics.
- **ADR-0065**: Provider and Method Extension Boundaries for Future Plugins.

---

## 3. Key Invariants & Architectural Rules

1. **Pure Read-Only Quoting & Planning**: Neither `ShippingRateEngine` nor `FulfillmentPlanningService` causes any DB mutation, reservation, label purchase, or order generation.
2. **Deterministic Precedence**: Specificity matching (Exclusion > Postal Exact > Prefix/Range > Region > Country > Global).
3. **Strict Money & Precision**: All shipping rates, handling fees, markups, and thresholds use `MoneyValue` / integer minor units with explicit currency. No binary float arithmetic.
4. **Strict Tenant & Context Isolation**: Every zone, method, rule, carrier, credential, and mapping is tenant-scoped with zero fallback to Tenant 1. Unresolved contexts fail safely.
5. **Encrypted Provider Secrets**: Carrier credentials use Laravel encryption at rest, write-only endpoints, and are strictly hidden from serialization, API output, Livewire state, and audit logs.
6. **Normalized Provider DTOs**: No carrier-specific response structures leak into core domain.

---

## 4. Quality & Verification Gates

- Full Pest test suite across both `modules/Shipping/` and `modules/Fulfillment/`.
- PHPStan Level 8 static analysis with 0 errors across entire workspace.
- Pint code style formatting.
- Vite build verification.
- Migration rollback and re-migration verification.
