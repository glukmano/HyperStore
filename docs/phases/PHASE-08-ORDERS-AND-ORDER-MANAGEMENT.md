# PHASE-08 — ORDERS, ORDER LIFECYCLE & STATE MACHINE FOUNDATION
## Authoritative Engineering Specification

**Status**: `SPECIFICATION & PLANNING — AWAITING OWNER APPROVAL`  
**Phase Objective**: Build the first-party Order domain, immutable commercial snapshots, three-dimensional state machine (Order, Payment, Fulfillment), inventory reservation handoff, and customer/guest/control-center APIs for Hyper Commerce, consuming the accepted `CheckoutReadyResult` handoff from PHASE-07 without implementing payment gateway drivers, label purchasing, or financial ledger postings.

---

## 1. Domain Ownership & Boundaries

### 1.1 `modules/Order/`
- **Depends On**:
  - `modules/Checkout/` (specifically `CheckoutReadyResult`, `CheckoutSession` state contracts)
  - `modules/Inventory/` (reservation handoff and cancellation release contracts)
  - Platform Context (`Tenant`, `Store`, `Market`, `Channel`, `Currency`, `Locale`)
- **Does NOT Depend On**:
  - `modules/Cart/`
  - `modules/Pricing/` directly (consumes snapshot from Checkout)
  - `modules/Promotions/` directly (consumes snapshot from Checkout)
  - `modules/Taxes/` directly (consumes snapshot from Checkout)
  - `modules/Shipping/` directly (consumes snapshot from Checkout)
  - `modules/Payment/` (Phase-09)
  - `modules/Ledger/` (Phase-10)
- **Owns**:
  - `Order` (Master Order) aggregate and lifecycle.
  - `OrderItem` (Order Lines) with exact decimal quantities (`NUMERIC(20,8)`) and immutable pricing/tax/discount snapshots.
  - Three distinct status dimensions:
    1. `order_status` (`placed`, `confirmed`, `processing`, `completed`, `cancelled`, `failed`)
    2. `payment_status` (`pending`, `authorized`, `paid`, `partially_refunded`, `refunded`, `voided`, `failed`)
    3. `fulfillment_status` (`unfulfilled`, `partially_fulfilled`, `fulfilled`, `returned`, `cancelled`)
  - `OrderStatusHistory` / `OrderEvent` timeline tracking.
  - `order_operation_keys` durable idempotency store.
  - Order number generation (`ORD-YYYYMMDD-XXXX`, tenant-unique).
  - Customer ownership vs secure guest token verification.
  - Inventory reservation handoff & cancellation compensation release.

---

## 2. Invariants & Out of Scope

### 2.1 Out of Scope (Strictly Forbidden in Phase-08)
- ❌ Payment gateway drivers, payment captures, webhooks, or `PaymentIntent` integration (Phase-09).
- ❌ Financial Ledger double-entry postings (Phase-10).
- ❌ Vendor payouts, settlements, and commission calculation (Phase-11).
- ❌ Carrier shipping label purchase and physical tracking API webhooks (Phase-17).
- ❌ Customer returns / RMA workflow (Phase-17).
- ❌ Supplier purchase order execution / external dropshipping sync (Phase-13).
- ❌ Recurring subscription billing engine execution (Phase-15).
- ❌ Digital license-key inventory depletion / top-up API execution (Phase-17).

---

## 3. Database Schema Design

### 3.1 `orders`
- `id` (bigserial PK)
- `uuid` (UUID, unique index)
- `order_number` (varchar(64), unique per tenant: `UNIQUE(tenant_id, order_number)`)
- `tenant_id` (FK to `tenants.id`)
- `store_id` (FK to `stores.id`)
- `market_id` (FK to `markets.id`)
- `channel_id` (FK to `channels.id`)
- `user_id` (bigint nullable, FK to `users.id`)
- `guest_token_hash` (char(64) nullable, SHA-256)
- `checkout_id` (bigint, unique per tenant: `UNIQUE(tenant_id, checkout_id)`)
- `checkout_fingerprint` (char(64))
- `currency` (char(3))
- `locale` (varchar(16))
- `order_status` (varchar(32), default `'placed'`)
- `payment_status` (varchar(32), default `'pending'`)
- `fulfillment_status` (varchar(32), default `'unfulfilled'`)
- `merchandise_subtotal_minor` (bigint)
- `discount_total_minor` (bigint)
- `shipping_total_minor` (bigint)
- `tax_total_minor` (bigint)
- `grand_total_minor` (bigint)
- `customer_snapshot` (jsonb)
- `shipping_address_snapshot` (jsonb)
- `billing_address_snapshot` (jsonb)
- `pricing_snapshot` (jsonb)
- `tax_snapshot` (jsonb)
- `promotion_snapshot` (jsonb)
- `shipping_snapshot` (jsonb)
- `fulfillment_snapshot` (jsonb)
- `reservation_references` (jsonb)
- `version` (int, default 1)
- `placed_at` (timestamp with time zone)
- `confirmed_at` (timestamp with time zone nullable)
- `completed_at` (timestamp with time zone nullable)
- `cancelled_at` (timestamp with time zone nullable)
- `created_at`, `updated_at`

### 3.2 `order_items`
- `id` (bigserial PK)
- `tenant_id` (FK to `tenants.id`)
- `order_id` (FK to `orders.id` on delete cascade)
- `product_id` (bigint)
- `variant_id` (bigint nullable)
- `sku_snapshot` (varchar(128))
- `name_snapshot` (varchar(255))
- `product_type_snapshot` (varchar(64))
- `quantity` (numeric(20,8))
- `unit_price_minor` (bigint)
- `subtotal_minor` (bigint)
- `discount_minor` (bigint)
- `tax_minor` (bigint)
- `total_minor` (bigint)
- `tax_class_id` (bigint nullable)
- `tax_rate_percent` (numeric(8,4) nullable)
- `selected_options_snapshot` (jsonb nullable)
- `customization_metadata_snapshot` (jsonb nullable)
- `created_at`, `updated_at`

### 3.3 `order_status_history`
- `id` (bigserial PK)
- `tenant_id` (FK to `tenants.id`)
- `order_id` (FK to `orders.id` on delete cascade)
- `status_dimension` (varchar(32), e.g. `'order'`, `'payment'`, `'fulfillment'`)
- `from_status` (varchar(32))
- `to_status` (varchar(32))
- `reason` (varchar(255) nullable)
- `actor_type` (varchar(32), e.g. `'customer'`, `'staff'`, `'system'`)
- `actor_id` (bigint nullable)
- `metadata` (jsonb nullable)
- `created_at`

### 3.4 `order_operation_keys`
- `id` (bigserial PK)
- `tenant_id` (FK to `tenants.id`)
- `idempotency_key` (varchar(128))
- `operation_type` (varchar(64), e.g. `'create_order'`, `'cancel_order'`)
- `checkout_id` (bigint nullable)
- `order_id` (bigint nullable)
- `request_hash` (char(64))
- `response_payload` (jsonb)
- `status` (varchar(32))
- `created_at`, `updated_at`
- `UNIQUE(tenant_id, operation_type, idempotency_key)`

---

## 4. State Machine & Transitions

### 4.1 Order Status Transitions
- `placed` $\longrightarrow$ `confirmed` (upon manual/automated order confirmation)
- `confirmed` $\longrightarrow$ `processing` (when preparation begins)
- `processing` $\longrightarrow$ `completed` (when fulfilled & paid)
- `placed` / `confirmed` / `processing` $\longrightarrow$ `cancelled` (releases reservations, marks cancellation)
- `placed` $\longrightarrow$ `failed` (if validation or technical barrier fails)

### 4.2 Payment Status Transitions
- `pending` $\longrightarrow$ `authorized` $\longrightarrow$ `paid`
- `paid` $\longrightarrow$ `partially_refunded` $\longrightarrow$ `refunded`
- `pending` / `authorized` $\longrightarrow$ `voided` / `failed`

### 4.3 Fulfillment Status Transitions
- `unfulfilled` $\longrightarrow$ `partially_fulfilled` $\longrightarrow$ `fulfilled`
- `fulfilled` $\longrightarrow$ `returned`
- `unfulfilled` $\longrightarrow$ `cancelled`

---

## 5. Architectural Decision Records (ADRs) to Implement
- **ADR-0083**: Order Module Ownership, Core Aggregates & Boundary Separation
- **ADR-0084**: Immutable CheckoutReadyResult Handoff & Commercial Snapshotting
- **ADR-0085**: Master & Seller/Vendor Order Hierarchy Foundation
- **ADR-0086**: Order Number Generation & Scoped Uniqueness Strategy
- **ADR-0087**: Three-Dimensional State Machines (Order, Payment, Fulfillment)
- **ADR-0088**: Order Snapshot Immutability & Line-Level Exact Fractional Fidelity
- **ADR-0089**: Inventory Reservation Handoff & Cancellation Compensation Lifecycle
- **ADR-0090**: Order Creation Idempotency & PostgreSQL Multi-Process Concurrency Control
- **ADR-0091**: Customer & Guest Order Ownership, Token Verification & IDOR Defense
- **ADR-0092**: Order Status History, Audit Trail & Typed Domain Events

---

## 6. Verification & Quality Gates
1. Pest feature & unit tests covering 100% of order invariants.
2. PostgreSQL multi-process concurrency harness testing race scenarios (two workers creating order from same ready checkout).
3. PHPStan Level 8 clean analysis (0 errors).
4. Pint code style verification (0 violations).
5. Vite production asset compilation.
6. Composer and NPM high-severity security audits (0 vulnerabilities).
7. Migration rollback and re-migration of Phase-08 migrations.
