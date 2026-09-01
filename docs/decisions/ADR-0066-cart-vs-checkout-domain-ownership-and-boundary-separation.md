# ADR-0066: Cart vs Checkout Domain Ownership and Boundary Separation

## Status
Accepted

## Context
In modern headless and modular e-commerce platforms, conflating Cart and Checkout into a single aggregate results in high coupling, leaky invariants, and unmaintainable state machines. Cart is an exploratory, highly mutable, long-lived domain where users add, modify, remove, and merge candidate purchase items with tentative pricing previews. In contrast, Checkout is an orchestration-driven, structured, short-lived transactional workflow that coordinates contact information, address snapshots, shipping rates, taxation, promotional coupon redemption, and multi-source inventory reservations into an immutable terminal handoff for future Order processing.

## Decision
1. **Cart Module (`modules/Cart/`)**:
   - Owns the Cart aggregate, CartLine entities, line item merging signatures, guest/customer ownership, optimistic concurrency versioning, and cart expiration.
   - Depends solely on Catalog capability contracts and Platform Context.
   - Does NOT perform inventory reservations, order generation, or payment handling.
2. **Checkout Module (`modules/Checkout/`)**:
   - Owns the CheckoutSession aggregate, explicit state machine transitions, immutable address and contact snapshots, and totals calculation.
   - Orchestrates Cart, Catalog, Pricing, Promotions, Taxes, Inventory, Fulfillment, and Shipping modules.
   - Produces the immutable `CheckoutReadyResult` handoff consumed by future Order/Payment modules.
   - Does NOT own catalog products, tax rules, pricing logic, inventory stock truth, or shipping calculators.

## Consequences
- Clean separation of concerns with zero upstream circular dependencies.
- Clear transactional boundaries between exploratory shopping and finalized checkout.
