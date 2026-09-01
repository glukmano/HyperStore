# PHASE-08 — ORDERS, ORDER LIFECYCLE & STATE MACHINE FOUNDATION
## Authoritative Engineering Specification (Final Approved Specification)

**Status**: `SPECIFICATION & PLANNING — AWAITING OWNER APPROVAL`  
**Phase Objective**: Build the first-party Order domain, immutable commercial snapshots, three-dimensional state machine (`order_status`, `payment_status`, `fulfillment_status`), inventory reservation handoff, and customer/guest/control-center APIs for Hyper Commerce, consuming the accepted `CheckoutReadyResult` handoff from PHASE-07 without implementing payment gateway drivers, label purchasing, or financial ledger postings.

---

## 1. Explicit Domain Boundaries & Module Architecture

### 1.1 `modules/Order/`
- **Depends On**:
  - `modules/Checkout/` (consumes `CheckoutReadyResult` and `CheckoutSession` state contracts)
  - `modules/Inventory/` (consumes `InventoryReservationServiceInterface` for reservation release upon cancellation)
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
  - `Order` aggregate and lifecycle (`placed`, `confirmed`, `processing`, `completed`, `cancelled`, `failed`).
  - `OrderItem` (Order Lines) with exact decimal quantities (`NUMERIC(20,8)`) and immutable pricing/tax/discount snapshots.
  - `OrderStatusHistory` / `OrderEvent` timeline tracking.
  - `order_operation_keys` aggregate-scoped durable idempotency store with mutual-exclusion CHECK constraint.
  - `order_number_counters` tenant/business-date-scoped atomic sequential counter (`ORD-YYYYMMDD-000001`) resolved using authoritative business timezone.
  - Customer ownership vs newly generated secure guest order access token (single first-response return policy).
  - Direct cancellation reservation release via `InventoryReservationServiceInterface::release`.

---

## 2. Invariants & Out of Scope

### 2.1 Out of Scope (Strictly Forbidden in Phase-08)
- ❌ Payment gateway drivers, payment captures, webhooks, or `PaymentIntent` integration (Phase-09).
- ❌ Payment state transition mutations (e.g. mark paid/authorized/refunded) (Phase-09).
- ❌ Fulfillment execution transition mutations (e.g. mark fulfilled/shipped/returned) (Phase-17).
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
- `order_number` (varchar(64), `UNIQUE(tenant_id, order_number)`)
- `tenant_id` (FK to `tenants.id`)
- `store_id` (FK to `stores.id`)
- `market_id` (FK to `markets.id`)
- `channel_id` (FK to `channels.id`)
- `user_id` (bigint nullable, FK to `users.id`)
- `guest_token_hash` (char(64) nullable, SHA-256)
- `checkout_id` (bigint, `UNIQUE(tenant_id, checkout_id)`)
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
- `product_id` (bigint nullable, on delete set null)
- `variant_id` (bigint nullable, on delete set null)
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

### 3.4 `order_number_counters`
- `id` (bigserial PK)
- `tenant_id` (FK to `tenants.id`)
- `business_date` (char(8)) -- 'YYYYMMDD'
- `last_value` (bigint default 0)
- `created_at`, `updated_at`
- `UNIQUE(tenant_id, business_date)`

### 3.5 `order_operation_keys`
- `id` (bigserial PK)
- `tenant_id` (FK to `tenants.id`)
- `idempotency_key` (varchar(128))
- `operation_type` (varchar(64))
- `checkout_id` (bigint nullable)
- `order_id` (bigint nullable)
- `request_hash` (char(64))
- `response_payload` (jsonb)
- `status` (varchar(32))
- `created_at`, `updated_at`
- Check Constraint:
  `CHECK ((checkout_id IS NOT NULL AND order_id IS NULL) OR (checkout_id IS NULL AND order_id IS NOT NULL))`
- Partial Unique Indexes:
  - `CREATE UNIQUE INDEX uq_order_op_checkout ON order_operation_keys (tenant_id, checkout_id, operation_type, idempotency_key) WHERE checkout_id IS NOT NULL;`
  - `CREATE UNIQUE INDEX uq_order_op_order ON order_operation_keys (tenant_id, order_id, operation_type, idempotency_key) WHERE order_id IS NOT NULL;`

---

## 4. State Machines & Responsibilities

### 4.1 Order Status Lifecycle (Owned by Phase-08)
- `placed` $\longrightarrow$ `confirmed`
- `confirmed` $\longrightarrow$ `processing`
- `processing` $\longrightarrow$ `completed`
- `placed` / `confirmed` / `processing` $\longrightarrow$ `cancelled` (triggers `InventoryReservationServiceInterface::release` and updates `fulfillment_status = cancelled`)
- `placed` $\longrightarrow$ `failed`

### 4.2 Payment Status Representation (Owned by Phase-09)
- Initialized to `pending`. Transitions to `authorized`, `paid`, `refunded`, `voided` are owned and triggered strictly by Phase-09 payment modules.

### 4.3 Fulfillment Status Representation (Owned by Phase-17)
- Initialized to `unfulfilled`. Transitions to `partially_fulfilled`, `fulfilled`, `returned` are owned and triggered strictly by Phase-17 fulfillment modules.

---

## 5. Architectural Decision Records (ADRs) to Implement
- **ADR-0083**: Order Module Ownership, Core Aggregates & Boundary Separation
- **ADR-0084**: Immutable CheckoutReadyResult Handoff & Commercial Snapshotting
- **ADR-0085**: Tenant/Date-Scoped Atomic Order Number Generation Strategy
- **ADR-0086**: Three-Dimensional State Machines and Status Ownership Boundaries
- **ADR-0087**: Order Item Snapshot Immutability & Historical Independence from Catalog
- **ADR-0088**: Retained Inventory Reservation Lifecycle & Cancellation Release Contract
- **ADR-0089**: Aggregate-Scoped Order Idempotency & PostgreSQL Concurrency Invariants
- **ADR-0090**: Customer & Guest Order Ownership, Ephemeral Token Return & IDOR Defense
- **ADR-0091**: Order Status History, Audit Trail & Typed Domain Events

---

## 6. Verification & Quality Gates
1. Pest feature & unit tests covering 100% of order invariants.
2. PostgreSQL multi-process concurrency harness testing race scenarios.
3. PHPStan Level 8 clean analysis (0 errors).
4. Pint code style verification (0 violations).
5. Vite production asset compilation.
6. Composer and NPM high-severity security audits (0 vulnerabilities).
7. Migration rollback and re-migration of Phase-08 migrations.
