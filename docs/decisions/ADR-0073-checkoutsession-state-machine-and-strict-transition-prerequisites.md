# ADR-0073: Capability-Driven Checkout Prerequisites and State Machine

## Status
Accepted

## Context
Commercial carts contain varied product types (physical goods, digital downloads, software licenses, services, subscriptions, and backorders). Modeling Checkout as a rigid linear pipe with ad-hoc skipped states leads to fragile conditional logic and false failures.

## Decision
1. **Capability-Driven Prerequisite Resolver**:
   - Implemented via `CheckoutPrerequisiteResolverInterface`.
   - Prerequisites are evaluated dynamically from Catalog capability contracts:
     - **Physical Products**: Require contact, shipping address, fulfillment plan, valid shipping quote, and inventory reservations where tracked.
     - **Digital / Software Licenses / Subscriptions**: Require contact and digital fulfillment metadata; physical shipping and stock reservations are bypassed (`NO_SHIPPING_REQUIRED`).
     - **Backorders / Preorders**: Preserves readiness metadata without fake stock holds.
2. **State Progression**:
   - The state machine evaluates: "What unsatisfied prerequisites remain before transition to `ready_for_order`?" rather than hardcoding string checks on `product_type`.

## Consequences
- Clean, extensible multi-product checkout flow.
- Zero hardcoded product type assumptions in Checkout orchestration.
