# PHASE-09 — PAYMENTS & PAYMENT ORCHESTRATION FOUNDATION
## Authoritative Engineering Specification & Implementation Plan (Final Frozen)

**Status**: `SPECIFICATION & PLANNING — AWAITING OWNER APPROVAL`  
**Authoritative Baseline Commit**: `7b9059de8dfd38dd30eb4bd2dd1b179c12a2dd5d`  
**Phase Objective**: Design and implement the first-party `modules/Payment` bounded context, provider-neutral gateway abstraction, separate Payment aggregate (order obligation) and PaymentTransaction (provider attempt) state machines, aggregate-scoped idempotency, two-phase remote call consistency supporting unknown outcomes, internal zero-total settlement, and one-way synchronization into `Order.payment_status`, without implementing financial ledger postings, vendor marketplace split payouts, or wallet engines.

---

## 1. Authoritative Domain Boundaries & Cardinality

### 1.1 Payment as Order Payment Obligation
`Payment` models the commercial payment obligation for an Order:
* **Cardinality**: Exactly one semantic `Payment` aggregate corresponds to one `Order` (`UNIQUE(tenant_id, order_id)`).
* **Authoritative Totals**:
  $$\text{payment.amount\_minor} === \text{order.grand\_total\_minor}$$
  $$\text{payment.currency} === \text{order.currency}$$
* `Payment` does NOT represent a single gateway attempt. Provider interactions belong under `Payment` as individual child `PaymentTransaction` entities.
* A declined attempt leaves the `Payment` obligation in `pending`, permitting subsequent customer retries or alternative payment method attempts without creating duplicate commercial obligations.

### 1.2 Unidirectional Dependency Direction
* **Strict One-Way Dependency**: `modules/Payment` $\longrightarrow$ `modules/Order`.
* `modules/Order` contains ZERO imports or awareness of `modules/Payment` models, event classes, or gateway types.
* `modules/Order` exposes a dedicated synchronization contract:
  ```php
  namespace Modules\Order\Contracts;
  
  interface OrderPaymentSynchronizationServiceInterface
  {
      public function syncPaymentStatus(int $tenantId, int $orderId, PaymentStatus $status, string $reason, array $metadata = []): Order;
  }
  ```
* When `modules/Payment` resolves an authoritative state transition, its application services directly call `OrderPaymentSynchronizationServiceInterface`.

---

## 2. Payment vs PaymentTransaction Responsibilities

| Dimension | `Payment` (Aggregate Root) | `PaymentTransaction` (Operation / Attempt) |
| :--- | :--- | :--- |
| **Concept** | Order commercial payment obligation | An individual gateway attempt or internal operation |
| **Identity** | Immutable UUID, integer `id`, `(tenant_id, order_id)` | Immutable integer `id`, `payment_id`, `payment_operation_key_id` |
| **Scope** | Whole Order payment lifecycle | Single execution (`purchase`, `authorize`, `capture`, `void`, `refund`, `zero_total_settlement`) |
| **Amounts** | `amount_minor`, `authorized_amount_minor`, `captured_amount_minor`, `refunded_amount_minor` | `amount_minor`, `currency` of this specific operation |
| **Provider Coupling** | Neutral (unbound to any single provider) | Bound to `provider_code`, `provider_reference`, `provider_response_code` |
| **Lifecycle** | Multi-attempt lifecycle (`pending`, `authorized`, `captured`, `partially_refunded`, `refunded`, `cancelled`) | Single-attempt outcome (`pending`, `success`, `failure`, `action_required`, `unknown`) |
| **Failure Semantics**| Remains open (`pending`) upon attempt decline for customer retry | Terminal for this specific gateway attempt (`failure`) |
| **Client Action** | None | Holds ephemeral `action_payload` (redirect URL, client secret) |

---

## 3. Order Payment Projection & Lifecycle Decoupling

### 3.1 Closed Owner Decisions
* **Payment Capture Does NOT Auto-Confirm Order**:
  $$\text{Payment captured} \implies \text{Order.payment\_status} = \text{paid}$$
  $$\text{Order.order\_status remains unchanged (e.g. placed)}$$
  Order confirmation remains an independent business operation in `modules/Order`.
* **No Generic Order State Machine Changes**:
  `OrderStateMachineService` remains strictly dedicated to `StatusDimension::ORDER`. Payment projection updates are executed exclusively via `OrderPaymentSynchronizationServiceInterface`.
* **Accepted Order Payment Vocabulary**:
  Only the already accepted [PaymentStatus.php](file:///Applications/MAMP/htdocs/HyperStore/modules/Order/Enums/PaymentStatus.php) cases are projected:
  - `Payment::authorized` $\longrightarrow$ `Order.payment_status = authorized`
  - `Payment::captured` $\longrightarrow$ `Order.payment_status = paid`
  - `Payment::refunded` (full) $\longrightarrow$ `Order.payment_status = refunded`
  - `Payment::cancelled` (before capture) $\longrightarrow$ `Order.payment_status = voided`
  - Partial capture / partial refund leaves `Order.payment_status` at its current milestone (`authorized` or `paid`).

---

## 4. Final State Machines

### 4.1 Payment Aggregate State Machine
```
[ pending ] ──┬──> [ authorized ] ──┬──> [ captured ] ──┬──> [ partially_refunded ] ──> [ refunded ]
              │                     │                   │
              │                     └──> [ cancelled ]  └──> [ refunded ]
              │
              ├──> [ captured ]
              └──> [ cancelled ]
```
* `pending`: Payment obligation created. Transactions may be attempted, declined, and retried.
* `authorized`: Funds reserved by a gateway transaction. Can receive partial captures while remaining `authorized`.
* `captured`: Total required funds settled ($captured\_amount\_minor === payment.amount\_minor$).
* `partially_refunded`: $0 < \text{refunded\_amount\_minor} < \text{captured\_amount\_minor}$.
* `refunded`: $\text{refunded\_amount\_minor} === \text{captured\_amount\_minor}$.
* `cancelled`: Terminal cancellation before capture (e.g. order cancelled).

### 4.2 PaymentTransaction State Machine
```
[ pending ] ──┬──> [ success ]
              ├──> [ failure ]
              ├──> [ action_required ] ──┬──> [ success ]
              │                          └──> [ failure ]
              └──> [ unknown ] ──────────┬──> [ success ]
                                         └──> [ failure ]
```
* `pending`: Pre-call local database record committed; awaiting gateway dispatch.
* `action_required`: Gateway requires customer intervention (3DS, redirect URL).
* `success`: Gateway settled or authorized the transaction.
* `failure`: Gateway declined or returned a terminal network error.
* `unknown`: Network timeout or 5xx where remote outcome is unconfirmed. Persisted status remains `unknown` until reconciliation settles it to `success` or `failure`. (No redundant `still_pending` transaction state).

---

## 5. Gateway Reconciliation Interface & Protocol

### 5.1 Provider Reconciliation Contract
```php
namespace Modules\Payment\Contracts;

use Modules\Payment\DTOs\GatewayReconciliationRequest;
use Modules\Payment\DTOs\GatewayReconciliationResult;

interface PaymentGatewayReconciliationInterface
{
    /**
     * Determine if this gateway driver supports out-of-band transaction reconciliation.
     */
    public function supportsReconciliation(): bool;

    /**
     * Inquire the remote provider for the authoritative status of a prior operation
     * without re-executing any monetary transfer.
     */
    public function reconcileOperation(GatewayReconciliationRequest $request): GatewayReconciliationResult;
}
```

### 5.2 Gateway Reconciliation Protocol
1. When a gateway call throws a network timeout or connection reset:
   - `PaymentTransaction.status` is set to `'unknown'`.
   - The idempotency lease is released, preserving the transaction record and its `provider_idempotency_key`.
2. When client retries the operation:
   - System detects an existing transaction in `'unknown'` status.
   - System does NOT invoke `purchase()` or `authorize()`.
   - If gateway implements `PaymentGatewayReconciliationInterface` and `supportsReconciliation()` is true:
     - Invoke `reconcileOperation()`.
     - Settle `PaymentTransaction` to `success` or `failure` based on remote truth.
   - If gateway does not support lookup or reconciliation returns `still_pending` or `unknown`:
     - Local `PaymentTransaction.status` remains `'unknown'`.
     - Fail closed with `PaymentReconciliationPendingException` (422) without re-charging the customer.

---

## 6. Order Cancellation $\to$ Payment Reconciliation Boundary

1. `OrderCancellationService` remains completely untouched. No gateway calls are placed inside Order cancellation.
2. In `modules/Payment/Listeners/OrderCancelledListener.php`, Payment registers an event listener for `Modules\Order\Events\OrderCancelled`:
   - Tenant-scopes lookup for the associated `Payment`.
   - If `Payment` status is `pending`:
     - Updates Payment to `cancelled`.
     - Calls `OrderPaymentSynchronizationServiceInterface->syncPaymentStatus(..., PaymentStatus::VOIDED)`.
   - If `Payment` status is `authorized`:
     - Local Payment marks cancellation/void reconciliation required.
     - Invokes `PaymentCancellationService` outside the original Order transaction.
     - Final `Payment.cancelled` and `Order.payment_status = voided` occur only after authoritative void confirmation.
   - If `Payment` status is `captured`:
     - Funds were already collected. Order cancellation proceeds.
     - Listener records `metadata['cancellation_reconciliation_required'] = true`.
     - Emits `PaymentReconciliationRequired` domain event to alert staff/accounting. Zero synchronous remote refunds occur inside Order cancellation.

---

## 7. Zero-Total Internal Settlement (No Fake Gateway)

* Orders with `order.grand_total_minor === 0` are handled entirely through an internal application path:
  1. `PaymentInitiationService` asserts `order.grand_total_minor === 0`.
  2. Creates `Payment` with `amount_minor = 0`, `currency = order.currency`, `status = 'captured'`, `captured_amount_minor = 0`.
  3. Inserts `payment_transactions` record:
     * `operation_type = 'zero_total_settlement'`
     * `status = 'success'`
     * `amount_minor = 0`
     * `provider_code = NULL`, `provider_reference = NULL`
  4. Invokes `OrderPaymentSynchronizationServiceInterface` $\longrightarrow$ `Order.payment_status = paid`.
  5. `PaymentGatewayInterface` is **never** invoked. Zero external references are fabricated.

---

## 8. Single Idempotency Authority & Conceptual Disambiguation

`payment_operation_keys` is the **single authority** for durable operation leasing and request deduplication. `payment_transactions` holds a nullable foreign key reference to `payment_operation_keys(id)`.

### Conceptual Disambiguation
1. **Client Idempotency Key**:
   Caller-supplied idempotency token (e.g. `UUID` from header `Idempotency-Key`). Stored exclusively in `payment_operation_keys.idempotency_key`.
2. **Internal Operation Record Identity**:
   Primary key `payment_operation_keys.id`. Referenced by `payment_transactions.payment_operation_key_id`.
3. **Provider Idempotency Key**:
   Deterministically generated internal string sent to payment gateway to guarantee provider-side deduplication. Stored in `payment_transactions.provider_idempotency_key` (e.g. `hyp_tx_42_a1b2c3d4`).
4. **Provider Transaction Reference**:
   External reference returned by the gateway upon execution (e.g. Stripe charge ID `ch_3Mv...`). Stored in `payment_transactions.provider_reference`.

---

## 9. Final Relational Persistence Schema & Migration Ordering

To guarantee clean, forward migration execution without circular foreign key dependencies, migrations are created in this strict order:

```
1. payments (references tenants, orders)
2. payment_operation_keys (references tenants, orders, payments)
3. payment_transactions (references tenants, payments, payment_operation_keys)
```

### 9.1 `payments`
```sql
CREATE TABLE payments (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    uuid UUID NOT NULL UNIQUE,
    order_id BIGINT NOT NULL REFERENCES orders(id),
    status VARCHAR(32) NOT NULL, -- pending, authorized, captured, partially_refunded, refunded, cancelled
    amount_minor BIGINT NOT NULL CHECK (amount_minor >= 0),
    currency VARCHAR(3) NOT NULL,
    authorized_amount_minor BIGINT NOT NULL DEFAULT 0 CHECK (authorized_amount_minor >= 0),
    captured_amount_minor BIGINT NOT NULL DEFAULT 0 CHECK (captured_amount_minor >= 0),
    refunded_amount_minor BIGINT NOT NULL DEFAULT 0 CHECK (refunded_amount_minor >= 0),
    captured_at TIMESTAMP WITH TIME ZONE NULL,
    authorized_at TIMESTAMP WITH TIME ZONE NULL,
    cancelled_at TIMESTAMP WITH TIME ZONE NULL,
    metadata JSONB NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_payments_tenant_order UNIQUE (tenant_id, order_id)
);
CREATE INDEX idx_payments_tenant_status ON payments(tenant_id, status);
```

### 9.2 `payment_operation_keys`
```sql
CREATE TABLE payment_operation_keys (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    idempotency_key VARCHAR(255) NOT NULL,
    operation_type VARCHAR(64) NOT NULL,
    order_id BIGINT NOT NULL REFERENCES orders(id),
    payment_id BIGINT NULL REFERENCES payments(id) ON DELETE SET NULL,
    request_hash VARCHAR(64) NOT NULL,
    response_payload JSONB NULL,
    error_payload JSONB NULL,
    status VARCHAR(32) NOT NULL, -- started, completed, failed
    lease_expires_at TIMESTAMP WITH TIME ZONE NULL,
    completed_at TIMESTAMP WITH TIME ZONE NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX uq_payment_op_keys_order ON payment_operation_keys (tenant_id, order_id, operation_type, idempotency_key)
    WHERE operation_type = 'initiate_payment';
CREATE UNIQUE INDEX uq_payment_op_keys_payment ON payment_operation_keys (tenant_id, payment_id, operation_type, idempotency_key)
    WHERE payment_id IS NOT NULL;
```

### 9.3 `payment_transactions`
```sql
CREATE TABLE payment_transactions (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    payment_id BIGINT NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
    payment_operation_key_id BIGINT NULL REFERENCES payment_operation_keys(id) ON DELETE SET NULL,
    operation_type VARCHAR(32) NOT NULL, -- purchase, authorize, capture, void, refund, zero_total_settlement
    status VARCHAR(32) NOT NULL, -- pending, success, failure, action_required, unknown
    amount_minor BIGINT NOT NULL CHECK (amount_minor >= 0),
    currency VARCHAR(3) NOT NULL,
    provider_code VARCHAR(64) NULL, -- NULL for internal operations
    payment_method_type VARCHAR(64) NULL,
    provider_reference VARCHAR(255) NULL,
    provider_idempotency_key VARCHAR(255) NULL,
    provider_response_code VARCHAR(64) NULL,
    normalized_error_code VARCHAR(64) NULL,
    action_type VARCHAR(64) NULL,
    action_payload JSONB NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_payment_transactions_lookup ON payment_transactions(tenant_id, payment_id, status);
CREATE INDEX idx_payment_transactions_provider_ref ON payment_transactions(tenant_id, provider_reference) WHERE provider_reference IS NOT NULL;
CREATE INDEX idx_payment_transactions_provider_idemp ON payment_transactions(tenant_id, provider_idempotency_key) WHERE provider_idempotency_key IS NOT NULL;
```

---

## 10. True Multi-Process Concurrency Race Matrix

All concurrency tests will run against true multi-process PostgreSQL:
* **Race A**: Two simultaneous `initiatePayment` requests for same Order $\to$ exactly one `Payment` aggregate created.
* **Race B**: Same initiate idempotency key + same fingerprint in parallel $\to$ exactly one transaction executed, second receives identical replayed response.
* **Race C**: Same initiate idempotency key + conflicting fingerprint in parallel $\to$ deterministic 409 `IdempotencyFingerprintMismatchException`.
* **Race D**: Two distinct idempotency keys racing to initiate payment for same Order $\to$ database semantic unique constraint `uq_payments_tenant_order` guarantees exactly one Payment.
* **Race E**: Gateway success vs client retry $\to$ single provider charge executed.
* **Race F (Concrete Timeout & Reconciliation)**:
  - Worker 1 initiates payment; gateway executes charge remotely but network drops before response.
  - Transaction is marked `unknown`.
  - Worker 2 retries with same idempotency key.
  - System detects `unknown` transaction, bypasses `purchase()`, invokes `reconcileOperation(provider_idempotency_key)`, reconciles remote success, and updates local transaction. Exactly ONE remote financial operation occurs.
* **Race G**: Two parallel `capture` requests for same authorized payment $\to$ total captured amount cannot exceed authorized amount.
* **Race H**: Two parallel `refund` requests $\to$ total refunded amount cannot exceed captured amount.
* **Race I**: Payment initiation racing Order cancellation $\to$ deterministic resolution without invalid state combination.
* **Race J**: Zero-total parallel initiation $\to$ exactly one Payment aggregate, zero gateway calls, deterministic replay.
* **Race K**: Stale failure response arriving after success state $\to$ monotonic state machine prevents status regression.

---

## 11. Revised ADR List (Beginning at ADR-0093)

* **ADR-0093**: Payment Bounded Context Ownership, Unidirectional Order Decoupling & Event Synchronization
* **ADR-0094**: Provider-Neutral Gateway & Transaction Attempt Architecture
* **ADR-0095**: Two-Phase Remote Gateway Consistency, UNKNOWN State & Out-of-Band Reconciliation
* **ADR-0096**: Payment Aggregate (Obligation) & Transaction (Attempt) State Machines
* **ADR-0097**: Aggregate-Scoped Payment Idempotency & Provider Idempotency Derivation
* **ADR-0098**: Zero-Total Order Internal Settlement Policy
* **ADR-0100**: PCI Boundary & Sensitive Payment Data Isolation
*(ADR-0099 deferred until live webhook infrastructure is integrated).*

---

