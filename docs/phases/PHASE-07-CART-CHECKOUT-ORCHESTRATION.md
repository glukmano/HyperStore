# PHASE-07 — CART & CHECKOUT ORCHESTRATION
## Authoritative Engineering Specification (Final Approved Specification)

**Status**: `SPECIFICATION & PLANNING — AWAITING FINAL PLAN APPROVAL`  
**Phase Objective**: Build the first-party Cart and Checkout orchestration foundation for Hyper Commerce, producing an immutable-ready `CheckoutReadyResult` handoff for future Order/Payment phases without implementing Orders, Payments, or physical label purchases.

---

## 1. Explicit Domain Boundaries & Module Architecture

### 1.1 `modules/Cart/`
- **Depends On**: `modules/Catalog/` capability contracts, Platform Context (`Tenant`, `Store`, `Market`, `Channel`, `Currency`, `Locale`).
- **Does NOT Depend On**: `modules/Pricing/`, `modules/Promotions/`, `modules/Taxes/`, `modules/Inventory/`, `modules/Shipping/`, `modules/Checkout/`.
- **Owns**:
  - `Cart` aggregate & lifecycle (`active`, `converted`, `abandoned`, `expired`, `locked`).
  - `CartLine` entities, capability-validated quantities (`CartQuantity` stored in `NUMERIC(20,8)`), selected configuration options, customization metadata, and normalized merge signatures.
  - Customer ownership vs. secure cryptographically opaque guest token (SHA-256 hashed at rest).
  - Cart get-or-create semantics and merging (guest $\rightarrow$ authenticated customer) with catalog purchasability revalidation and stale pricing flagging.
  - Full context binding (`tenant_id`, `store_id`, `market_id`, `channel_id`, `currency`, `locale`).
  - Optimistic concurrency control / version tracking (`version`).
  - Cart expiration policy & scheduled cleanup (`hyper:cart:cleanup-expired`).

### 1.2 `modules/Checkout/`
- **Depends On**:
  - `modules/Cart/` contracts
  - `modules/Catalog/` capability contracts
  - `modules/Pricing/` price book & calculation contracts
  - `modules/Promotions/` promotion evaluation & coupon contracts
  - `modules/Taxes/` (within Pricing) tax calculation contracts
  - `modules/Inventory/` reservation & availability contracts
  - `modules/Fulfillment/` fulfillment planning contracts
  - `modules/Shipping/` rate quoting & restriction contracts
  - Platform Context
- **Owns**:
  - `CheckoutSession` aggregate & capability-driven state machine.
  - `checkout_operation_keys` durable idempotency store:
    - Checkout creation: scoped to `(tenant_id, cart_id, operation_type, idempotency_key)`
    - Checkout mutations: scoped to `(tenant_id, checkout_session_id, operation_type, idempotency_key)`
  - Snapshotting immutable customer contact, billing address, and shipping address.
  - Totals orchestration (`merchandise_subtotal`, `line_discounts`, `cart_discounts`, `shipping_original`, `shipping_discount`, `shipping_final`, `tax_total`, `grand_total`).
  - Temporary coupon application & hold evaluation (usage increment deferred to Order phase).
  - Fulfillment planning & multi-source routing orchestration.
  - Shipping rate selection, comprehensive fingerprinting & freshness re-validation.
  - Multi-source inventory reservation orchestration with deterministic lock ordering (`source_id ASC, product_id ASC, variant_id ASC`) inside an outer PostgreSQL transaction (`DB::transaction()`).
  - Capability-driven prerequisite resolution via `CheckoutPrerequisiteResolver` consuming Catalog capability contracts.
  - Production of the terminal, immutable `CheckoutReadyResult` handoff.

### 1.3 Strict Upward & Inward Invariants
- `modules/Inventory/` MUST NOT depend on Checkout or Cart.
- `modules/Shipping/` MUST NOT depend on Checkout or Cart.
- `modules/Pricing/` & `modules/Promotions/` MUST NOT depend on Checkout or Cart.
- `modules/Catalog/` MUST NOT depend on Checkout or Cart.
- `modules/Fulfillment/` MUST NOT depend on Checkout or Cart.
- Checkout MUST NOT create Order models, capture payments, post financial ledgers, or purchase shipping labels.

---

## 2. Key Architecture Invariants & Proved Behaviors

### 2.1 Reservation Consistency Model: Single Database Transaction (Option A)
- `CheckoutInventoryReservationOrchestrator` owns the outer `DB::transaction()`.
- Proved from Phase-05 implementation: all reservation allocations, lock acquisitions (`StockItem::lockForUpdate()`), and reservation record creations execute on the default PostgreSQL connection using nested `DB::transaction()` savepoints.
- Process:
  1. Lock `CheckoutSession` (`FOR UPDATE`).
  2. Verify Cart version (`checkout.evaluated_cart_version == cart.version`).
  3. Revalidate `FulfillmentPlan`.
  4. Sort source allocations deterministically (`inventory_source_id ASC, product_id ASC, variant_id ASC`).
  5. Call `InventoryReservationServiceInterface::reserve()` for every required allocation.
  6. Persist reservation references in `checkout_sessions.reservation_references`.
  7. Transition checkout state to `inventory_reserved`.
  8. Mark idempotency operation `completed`.
  9. `COMMIT`.
- If any source reservation fails or throws an exception, PostgreSQL executes an **immediate atomic rollback** of the outer transaction. No compensation is needed or claimed.

### 2.2 Capability-Driven Quantity Validation (`CartQuantity`)
- Storage uses `NUMERIC(20,8)` as a high-capacity technical upper bound.
- Validation is derived dynamically from Catalog capability contracts / Product Type Registry capabilities and UOM contracts:
  - Discrete items (licenses, subscriptions, standard goods): integer only (`scale = 0`, step = 1).
  - Fractional items (fabrics, bulk goods): fractional allowed based on UOM capability scale and increment rules.
- No binary floating point arithmetic.

### 2.3 Comprehensive Shipping Quote Fingerprint
- Rate-relevant fingerprint `quote_fingerprint` computed over normalized JSON containing:
  `tenant_id`, `store_id`, `market_id`, `channel_id`, `currency`, `destination`, `method_id`, `method_code`, `carrier_code`, `service_code`, `fulfillment_allocations`, `packages`, `physical_lines`, `promotion_benefits`, `original_amount`, `final_amount`, `breakdown`, `provider_version`.
- Any mutation to cart items, destination, or source allocations alters the fingerprint, immediately invalidating the selected quote.

### 2.4 Capability-Driven Checkout Prerequisite Model
- `CheckoutPrerequisiteResolver` evaluates line item capabilities via Catalog capability contracts (`requiresPhysicalShipping()`, `requiresInventory()`, `isPurchasable()`) rather than raw `product_type` strings:
  - Digital/Service items: zero shipping address or quote required (`NO_SHIPPING_REQUIRED`), zero physical stock reservations.
  - Physical items: requires address, fulfillment plan, valid shipping quote, and stock reservations.
  - Backorder/Preorder: preserves readiness state without fake stock holds.

### 2.5 Aggregate-Scoped Idempotency & Mutually Exclusive Columns
- `checkout_operation_keys` enforces a PostgreSQL CHECK constraint:
  `CHECK ((cart_id IS NOT NULL AND checkout_session_id IS NULL) OR (cart_id IS NULL AND checkout_session_id IS NOT NULL))`
- **Checkout Creation**: Scoped to Cart (`cart_id` NOT NULL, `checkout_session_id` NULL, `operation_type = 'create_checkout'`, `idempotency_key`).
- **Checkout Mutations**: Scoped to CheckoutSession (`checkout_session_id` NOT NULL, `cart_id` NULL, `operation_type`, `idempotency_key`).
- Partial unique indexes enforce uniqueness independently per scope.
- Cart creation: uses PostgreSQL active-cart unique constraints (`(tenant_id, user_id, store_id, market_id, channel_id) WHERE status = 'active'`) and `getOrCreateActiveCart()` semantics.
- Checkout creation: scoped to `(tenant_id, cart_id, operation_type = 'create_checkout', idempotency_key)`.
- Checkout mutations: scoped to `(tenant_id, checkout_session_id, operation_type, idempotency_key)`.
- Mismatched request fingerprints under the same idempotency key return HTTP 422 Conflict.
