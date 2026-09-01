START PHASE-08.

PHASE-07 — CART & CHECKOUT ORCHESTRATION is CLOSED & ACCEPTED.

Accepted PHASE-07 baseline commit:

5a349589d8c88915679242acd9523584c2a37215

Do NOT reopen or refactor accepted PHASE-07 architecture unless inspection reveals
a concrete regression that blocks PHASE-08.

==================================================
STEP 1 — READ AUTHORITATIVE PROJECT SOURCES
==================================================

Before proposing or implementing anything, read:

PROJECT_MASTER_PLAN.md

all accepted ADRs

docs/phases/

especially:

docs/phases/PHASE-07-CART-CHECKOUT-ORCHESTRATION.md

and inspect the actual accepted source code for:

modules/Checkout/
modules/Cart/
modules/Inventory/
modules/Fulfillment/
modules/Shipping/
modules/Pricing/
modules/Promotions/
modules/Catalog/

Also inspect Platform Context contracts and current authorization/audit conventions.

Determine the EXACT PHASE-08 title, scope, deliverables, dependencies,
out-of-scope boundaries, and acceptance criteria from PROJECT_MASTER_PLAN.md.

PROJECT_MASTER_PLAN.md remains authoritative.

DO NOT MODIFY PROJECT_MASTER_PLAN.md.

==================================================
STEP 2 — INSPECT THE PHASE-07 HANDOFF
==================================================

Inspect the actual accepted:

CheckoutReadyResult
ready_snapshot
CheckoutSession ready_for_order semantics
reservation_references
pricing_snapshot
tax_snapshot
promotion_snapshot
selected_shipping_quote
fulfillment_plan / allocation snapshots
customer/contact snapshot
shipping/billing address snapshots
Cart version/fingerprint
Checkout idempotency implementation

Determine exactly what PHASE-08 is allowed to consume.

Do not reconstruct Order truth from mutable Cart/Catalog state if the accepted
CheckoutReadyResult already contains the immutable commercial snapshot.

The ready checkout handoff is the boundary.

==================================================
STEP 3 — PRESERVE ACCEPTED DOMAIN OWNERSHIP
==================================================

Do not move business ownership from accepted modules.

Catalog owns:
product/configuration truth.

Pricing owns:
price/money evaluation.

Promotions owns:
promotion/coupon evaluation.

Tax owns:
tax calculation truth.

Inventory owns:
stock/reservations.

Fulfillment owns:
source allocation planning.

Shipping owns:
shipping/rate truth.

Cart owns:
cart lifecycle.

Checkout owns:
checkout orchestration and immutable ready handoff.

PHASE-08 must consume these contracts rather than duplicate their rules.

==================================================
STEP 4 — PHASE-08 MUST NOT SILENTLY IMPLEMENT FUTURE PHASES
==================================================

After reading PROJECT_MASTER_PLAN.md, explicitly list PHASE-08 out-of-scope items.

At minimum, unless PROJECT_MASTER_PLAN.md explicitly says otherwise:

DO NOT implement payment gateways.
DO NOT create PaymentIntent/capture/refund behavior.
DO NOT create Financial Ledger behavior.
DO NOT implement vendor payouts/settlements.
DO NOT purchase shipping labels.
DO NOT implement returns/RMA.
DO NOT implement supplier purchase-order execution.
DO NOT implement recurring billing.
DO NOT implement top-up/license fulfillment engines.
DO NOT begin PHASE-09 or any later phase.

If PHASE-08 is the Order domain, Order must remain payment-provider agnostic.

==================================================
STEP 5 — ARCHITECTURE QUESTIONS THAT THE PLAN MUST RESOLVE
==================================================

Based on the actual Master Plan, resolve all PHASE-08 architecture decisions
before implementation.

If PHASE-08 is Orders / Order Foundation, explicitly address:

1. Order ownership and module boundary.

2. Exact relationship:

CheckoutReadyResult
→ Order creation.

3. Whether one ready Checkout may create:
- one Order
- Master Order + Vendor Orders
- or another model explicitly required by Master Plan.

Do not invent premature VendorOrder structure if not yet required by the current phase.

4. Immutable commercial snapshots:
- product identity
- SKU/name/display information
- variant/configuration
- exact quantity
- unit price
- discounts
- taxes
- shipping
- currency
- addresses
- fulfillment/source references
- promotion/coupon references
- totals.

Define what remains immutable after creation.

5. Order numbering:
- UUID/internal primary key
- human-facing order number
- tenant uniqueness
- generation concurrency
- no predictable security-sensitive identifiers.

6. Order status/state machine:
define typed states and valid transitions.

No arbitrary:

PATCH status

or free-form strings.

7. Financial/payment state must remain separate from Order state.

Do NOT collapse concepts such as:

order_status
payment_status
fulfillment_status

into one generic status.

8. Fulfillment state separation.

Order lifecycle must not pretend:
paid == fulfilled
or
fulfilled == shipped.

9. Inventory reservation handoff.

Determine what happens to PHASE-07 reservations when an Order is created:

- reservations remain associated with Checkout?
- ownership/reference transitions to Order?
- reservation metadata is snapshotted?
- when can reservation be released?
- which future phase converts reservations into committed stock movements?

Do not invent stock deduction semantics without verifying PHASE-05 and Master Plan.

10. Idempotent Order creation.

Retrying the same ready Checkout must never create duplicate Orders.

Define durable idempotency scope.

Prefer database-level invariant in addition to application logic.

11. Checkout → Order concurrency.

Two simultaneous Order creation calls for one ready Checkout:

exactly one semantic Order.

Use PostgreSQL-level proof.

12. Ready snapshot verification.

Order creation must verify:
- Checkout still ready_for_order
- immutable ready snapshot exists
- snapshot fingerprint/integrity is valid
- no mutable Cart reconstruction.

13. Customer/guest ownership and IDOR.

Customer Order APIs must be ownership-protected.

Staff/Admin APIs require RBAC + tenant scoping.

14. Guest Order access policy.

If supported by Master Plan:
use secure opaque credentials/token mechanism.

Do not expose Orders by sequential ID alone.

15. Order history / timeline.

Define typed Order events/status history rather than relying only on mutable state.

16. Audit trail and observability.

17. Cancellation semantics.

Clearly separate:
Order cancellation request/state
from future payment refund
and inventory release.

Do not silently refund because Order was cancelled.

18. Zero-total Orders.

Must remain valid if Checkout grand total is zero.

No fake Payment requirement.

19. Multi-currency.

Order currency is immutable snapshot currency.

No floating point.

20. Tenant / Store / Market / Channel snapshots.

Order must retain the commercial context that created it.

21. Locale/timezone snapshot policy.

Store UTC timestamps.

Display using explicit relevant timezone/context.

22. PII.

Snapshot only required fulfillment/billing/customer data.
Mask it in diagnostics/list APIs where appropriate.

23. API error contracts.

Define structured errors such as conceptually:

CHECKOUT_NOT_READY
CHECKOUT_ALREADY_ORDERED
CHECKOUT_SNAPSHOT_INVALID
ORDER_NOT_FOUND
ORDER_ACCESS_DENIED
INVALID_ORDER_TRANSITION
ORDER_ALREADY_CANCELLED

Use actual names appropriate to existing project conventions.

24. API surface.

Separate:
customer Order APIs
from Control Center/staff APIs.

25. Events/contracts for future phases.

Expose typed Order events/contracts for future:

Payment
Fulfillment execution
Ledger
Notifications

without implementing those phases now.

==================================================
STEP 6 — DATABASE REVIEW
==================================================

Do NOT blindly create every possible Order-related table.

Propose only tables required by PHASE-08.

Potential concepts to evaluate against the Master Plan:

orders
order_lines / order_items
order_status_history / order_events
order_addresses or immutable JSON snapshots
order_context snapshots
order_idempotency keys

Possibly future-only and therefore NOT current phase:

payments
payment_transactions
ledger_entries
shipments
returns
vendor_payouts
supplier_orders

Use PostgreSQL constraints for domain invariants where practical.

Examples to evaluate:

- one Order per ready Checkout
- tenant-safe foreign keys/composite integrity
- immutable checkout reference
- unique human Order number per required scope
- typed state CHECK constraints where appropriate

==================================================
STEP 7 — ADRs
==================================================

Use the next available ADR numbers.

Create ADRs for all material PHASE-08 decisions.

At minimum, if Orders are in scope, expect ADRs covering:

- Order domain ownership
- CheckoutReadyResult → Order handoff
- Order identity/numbering
- Order state machine
- payment/fulfillment status separation
- immutable Order snapshot strategy
- reservation reference/handoff policy
- Order creation idempotency
- Order concurrency
- customer/guest ownership
- cancellation boundary
- future Payment/Fulfillment contracts

Do not assign ADR numbers until you inspect existing ADR numbering.

==================================================
STEP 8 — REQUIRED TEST PLAN
==================================================

The PHASE-08 plan must include tests for every accepted invariant.

If Orders are in scope, include at minimum:

ORDER CREATION
- ready Checkout creates Order
- non-ready Checkout rejected
- expired/cancelled Checkout rejected
- missing/invalid ready snapshot rejected
- zero-total Order allowed
- immutable context/totals copied correctly

SNAPSHOTS
- Order preserves exact line quantities
- prices
- discounts
- tax
- shipping
- currency
- addresses
- product/variant/configuration
- fulfillment allocation references

IDEMPOTENCY
- retry same request/key returns same semantic Order
- same key + different fingerprint rejects
- same ready Checkout can never produce duplicate Order

CONCURRENCY
True PostgreSQL multi-process harness:

two workers
same ready Checkout
same/different allowed idempotency scenarios

DB final state:
exactly one semantic Order.

OWNERSHIP / SECURITY
- customer can access own Orders
- cannot access same-tenant other customer Order
- guest access policy
- cross-tenant rejected
- staff RBAC
- PII masking

STATE MACHINE
- valid transitions
- invalid transitions
- no generic arbitrary status write

BOUNDARIES
- Order creation does NOT:
  capture payment
  refund payment
  deduct stock outside accepted inventory policy
  purchase shipping label
  create ledger entry
  execute fulfillment provider

==================================================
STEP 9 — QUALITY / CONCURRENCY GATES
==================================================

Plan must retain:

php -d memory_limit=512M vendor/bin/pest

php -d memory_limit=512M vendor/bin/phpstan analyse \
-a phpstan-bootstrap.php \
--level=8 \
--memory-limit=512M

./vendor/bin/pint --test

npm run build

composer audit

npm audit --audit-level=high

PostgreSQL migration rollback/re-migration of ONLY PHASE-08 migrations.

True process-level PostgreSQL concurrency tests.

==================================================
STEP 10 — FIRST RESPONSE ONLY
==================================================

DO NOT IMPLEMENT PHASE-08 YET.

First:

1. Read PROJECT_MASTER_PLAN.md.
2. Inspect the accepted repository at commit/baseline:
   5a349589d8c88915679242acd9523584c2a37215
3. Determine exact PHASE-08 scope from the Master Plan.
4. Update/create:

docs/phases/PHASE-08-<AUTHORITATIVE-TITLE>.md

5. Return:

PHASE-08 IMPLEMENTATION PLAN

The response must explicitly contain:

- authoritative PHASE-08 title
- exact Master Plan scope
- module boundaries
- dependency graph
- proposed database changes
- state machine(s)
- CheckoutReadyResult handoff model
- reservation policy
- idempotency/concurrency design
- ownership/security model
- API surface
- ADR list using next actual numbers
- tests
- PostgreSQL concurrency harnesses
- quality gates
- explicit out-of-scope list
- confirmation PROJECT_MASTER_PLAN.md was not modified
- confirmation no PHASE-09 work was started

Then STOP.

WAIT FOR OWNER APPROVAL BEFORE WRITING PHASE-08 IMPLEMENTATION CODE.