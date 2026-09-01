Read PROJECT_MASTER_PLAN.md fully before doing anything.

PHASE-06 is CLOSED & ACCEPTED.

Accepted PHASE-06 final commit:

0a8b0d19348cfdf9d739df4f29aeb37d45759d62

We are now starting:

PHASE-07 — CART & CHECKOUT ORCHESTRATION

Before implementation:

1. Read:
   - PROJECT_MASTER_PLAN.md
   - all accepted ADRs
   - PHASE-04 Pricing/Taxes/Promotions final state
   - PHASE-05 Inventory final state
   - PHASE-06 Shipping/Fulfillment final state
   - Catalog contracts
   - Context system
   - Identity/Tenancy/Store/Market/Channel architecture
   - current module dependency graph

2. Load all relevant Skills, especially:
   - project-governance
   - architecture-boundaries
   - commerce-domain
   - multi-tenancy
   - multi-store-context
   - catalog
   - pricing-promotions
   - inventory
   - fulfillment-dropshipping
   - shipping
   - money-ledger
   - localization-markets-rtl
   - api-webhooks
   - security-hardening
   - testing-quality
   - performance-observability
   - documentation-adr

3. Run complete current project quality baseline before modifying code.

4. If any defect in PHASE-04/05/06 materially prevents correct Checkout behavior,
   STOP and report the blocker instead of silently working around it.

5. Create the authoritative phase specification first:

docs/phases/PHASE-07-CART-CHECKOUT-ORCHESTRATION.md

Then implement ONLY PHASE-07.

Do NOT modify PROJECT_MASTER_PLAN.md.

==================================================
PHASE OBJECTIVE
==================================================

Build the first-party Cart and Checkout orchestration foundation for Hyper Commerce.

PHASE-07 must establish:

- Cart domain
- Cart Lines
- Cart lifecycle
- Guest carts
- Authenticated customer carts
- Cart merging foundation
- Store/Market/Channel context binding
- Catalog product/variant resolution
- Product capability resolution
- Quantity validation
- Pricing snapshot/calculation orchestration
- Promotion/coupon integration
- Tax calculation orchestration
- Inventory availability validation
- Inventory reservation orchestration
- Fulfillment planning integration
- Shipping quote selection
- Non-physical fulfillment handling
- Mixed physical/digital carts
- Checkout session/state machine
- Customer/contact data boundary
- Billing/shipping address snapshots/DTOs
- Shipping selection
- Coupon application/removal
- Checkout totals
- Checkout recalculation
- checkout idempotency
- concurrency safety
- cart expiration
- reservation expiration/release
- API
- Control Center diagnostics where appropriate
- RBAC for management/diagnostics
- audit/security/events/observability
- comprehensive tests

IMPORTANT:

PHASE-07 DOES NOT IMPLEMENT:

- Final Order creation unless explicitly defined as the terminal handoff contract only
- Order management
- Payment capture/authorization
- Financial Ledger postings
- vendor payouts
- supplier purchase orders
- shipment execution
- actual shipping labels
- returns/RMA
- refunds
- subscription billing
- digital delivery execution
- top-up execution
- license-key consumption/delivery
- POS checkout execution
- customer mobile application
- vendor mobile application

PHASE-07 must produce a validated, immutable-ready Checkout result/handoff that a
future Order/Payment phase can consume.

Do NOT begin the next phase.

==================================================
1. DOMAIN BOUNDARIES
==================================================

Preferred modules:

modules/Cart/
modules/Checkout/

Cart owns:

- cart
- cart lines
- cart lifecycle
- quantity changes
- line metadata
- guest/customer ownership
- cart merge
- cart expiration
- cart-level selected options if appropriate

Checkout owns:

- checkout session
- orchestration
- totals
- pricing/tax/promotion evaluation
- fulfillment planning
- shipping selection
- reservation orchestration
- address/contact snapshots
- checkout validation
- state transitions
- immutable checkout-ready result

Checkout must NOT own:

- Product
- Pricing rules
- Promotions
- Inventory stock truth
- Fulfillment source truth
- Shipping methods/rates
- Payments
- Orders

==================================================
2. DEPENDENCY DIRECTION
==================================================

Expected:

Cart
→ Catalog contracts
→ Platform Context

Checkout
→ Cart
→ Catalog contracts
→ Pricing contracts
→ Promotion contracts
→ Tax contracts
→ Inventory reservation/availability contracts
→ Fulfillment contracts
→ Shipping contracts
→ Platform Context

Inventory MUST NOT depend on Checkout.

Shipping MUST NOT depend on Checkout.

Pricing MUST NOT depend on Checkout.

Catalog MUST NOT depend on Checkout.

Fulfillment MUST NOT depend on Checkout.

Document dependency graph in ADR.

==================================================
3. CART MODEL
==================================================

Create first-class Cart.

Conceptual fields:

- id
- uuid/public token
- tenant_id
- customer/user id nullable
- guest token nullable
- store_id
- market_id
- channel_id
- currency
- locale
- status
- expires_at
- created_at
- updated_at
- version / optimistic concurrency token if appropriate

Statuses conceptually:

active
converted
abandoned
expired
locked

Exact state model via ADR.

Do not use arbitrary string mutations.

==================================================
4. CART LINE MODEL
==================================================

CartLine concept:

- cart_id
- product_id
- variant_id nullable
- quantity
- product type/capability snapshot only if justified
- selected options/configuration
- customization metadata
- unit pricing reference/snapshot strategy
- line metadata
- fulfillment metadata hook

Do NOT copy full Product into Cart.

Do NOT trust client-supplied price.

==================================================
5. CART PRODUCT RESOLUTION
==================================================

On add/update:

Resolve Product and Variant through Catalog contracts.

Validate:

- tenant ownership
- Store eligibility
- Market eligibility
- Channel eligibility
- Product status
- Variant status
- purchasability
- sale availability
- Product Type capability

Do NOT trust external:

price
is_shippable
product_type
inventory availability
tax class

as business truth.

==================================================
6. PRODUCT TYPE CAPABILITY
==================================================

Use Catalog Product Type Registry.

Cart/Checkout must support from architecture:

Physical
Digital Download
License/Serial Key
Subscription
Top-Up
Gift Card
Service
Booking
Rental
Bundle
Variable
Configurable
Custom/Personalized
Affiliate/External
Preorder
Membership
Ticket/Event
Auction
Quote/RFQ
Wholesale
Made-to-Order
Print-on-Demand

PHASE-07 does not need to execute all post-purchase behavior.

But Checkout must not hardcode:

if physical
if digital

Use typed product capabilities for:

- purchasable
- requires inventory
- requires physical shipping
- requires fulfillment
- allows quantity
- future post-purchase execution mode

==================================================
7. CART QUANTITY
==================================================

Quantity rules must support exact quantity semantics.

Do not assume every Product Type uses integer quantity if Catalog permits UOM/fractional quantity.

Reuse approved Quantity/UOM architecture from Inventory/Catalog.

Validate:

- > 0
- scale
- allowed increments
- min/max quantity
- product-specific constraints

No binary float.

==================================================
8. CART LINE UNIQUENESS
==================================================

Define merge identity.

Same product/variant may merge only if:

- configuration identical
- personalization identical
- fulfillment-relevant options identical
- price-affecting option identity identical

Otherwise separate CartLine.

Use deterministic normalized signature/hash.

Do not merge customized items incorrectly.

==================================================
9. GUEST CART
==================================================

Support guest cart through secure opaque token.

Requirements:

- cryptographically strong token
- not guessable
- tenant scoped
- expires
- never exposes sequential database ID as authorization
- guest cannot access another guest cart

Do not use IP address as identity.

==================================================
10. AUTHENTICATED CART
==================================================

Authenticated users may have active cart according to documented policy.

Decide:

- one active cart per Store/Market/Channel
or
- multiple carts

Document in ADR.

Never merge carts from different Tenants.

==================================================
11. CART MERGE
==================================================

Support guest → authenticated merge foundation.

Rules:

- same Tenant
- compatible Store/Market/Channel
- deterministic line merge
- preserve quantities/options
- revalidate products/pricing
- do not trust stale guest state

Conflicts return structured result.

Add concurrency-safe merge test.

==================================================
12. CONTEXT BINDING
==================================================

Cart must be bound to:

Tenant
Store
Market
Channel
Currency
Locale

No:

tenant_id ?? 1
arbitrary Store fallback
arbitrary Market fallback

Context changes that would invalidate the Cart must be explicit.

==================================================
13. CURRENCY
==================================================

Cart currency is explicit.

All monetary values use approved MoneyValue / Brick Money architecture.

Do not accept client-computed totals.

If Market/Currency changes:
re-price explicitly.

No silent 1:1 conversion.

==================================================
14. CART PRICING ORCHESTRATOR
==================================================

Create CartPricingService / CartTotalsCalculator or equivalent.

It orchestrates existing Pricing contracts.

Input:

Cart + validated context

Output typed:

CartPricingResult

including:

- lines
- base subtotal
- price adjustments
- promotion discounts
- coupon discounts
- shipping amount if Checkout stage
- tax amount
- grand total

Do NOT duplicate pricing rules.

==================================================
15. PRICE SNAPSHOT STRATEGY
==================================================

Define via ADR:

Cart may display recalculated current prices.

Checkout finalization must use a validated pricing snapshot/version.

Do not silently trust historical Cart price forever.

Document:

- when price recalculates
- when snapshot becomes immutable
- invalidation rules

==================================================
16. COUPON INTEGRATION
==================================================

PHASE-04 coupon hooks must now be implemented.

Support:

apply coupon
remove coupon
validate coupon
coupon code normalization
tenant/store/market/channel scope
date validity
usage eligibility hook
minimum subtotal
product/category restrictions if Promotion supports them

Do not duplicate Promotion condition logic.

Checkout consumes typed Promotion result.

==================================================
17. COUPON CONCURRENCY
==================================================

Do not permanently consume coupon usage during simple Cart preview.

Coupon redemption/usage reservation strategy must be safe.

If actual coupon usage should only increment after future Order completion, document that.

PHASE-07 may create a Checkout-level temporary coupon hold only if required and safe.

Do not incorrectly burn coupons on abandoned carts.

==================================================
18. PROMOTION RECALCULATION
==================================================

Cart mutations must invalidate/recalculate Promotion results.

Examples:

quantity change
line removal
Market change
coupon change
shipping method change

No stale promotion result.

==================================================
19. TAX ORCHESTRATION
==================================================

Use PHASE-04 Tax contracts.

Checkout must provide typed taxable inputs:

- line amounts
- shipping taxable metadata
- destination
- product tax class
- Store/Market context

Do not implement tax rates inside Checkout.

==================================================
20. TAX ADDRESS BASIS
==================================================

Document which address drives tax evaluation:

shipping
billing
store
product-specific policy

Do not hardcode one globally if Tax module already defines policy.

==================================================
21. SHIPPING DESTINATION
==================================================

Reuse PHASE-06 ShippingDestination.

Do NOT duplicate address parsing logic.

Checkout shipping address snapshot can produce ShippingDestination DTO.

==================================================
22. CUSTOMER / CONTACT DATA
==================================================

Create minimal checkout contact boundary.

Conceptual:

CheckoutCustomerData

- email
- phone optional
- first name
- last name
- company optional
- tax/VAT ID hook
- customer_id nullable

Do not build full CRM.

==================================================
23. ADDRESS SNAPSHOT
==================================================

Checkout requires immutable address snapshots/VOs.

Shipping/Billing addresses must not depend on mutable future CustomerAddress row after checkout is finalized.

Fields:

- recipient/company
- lines
- postal code
- city
- region
- country
- phone where appropriate

Normalize country/postal without destroying display form.

==================================================
24. CUSTOMER ADDRESS PERSISTENCE
==================================================

PHASE-07 may introduce reusable CustomerAddress only if Master Plan already requires it.

Otherwise keep Checkout address snapshots and defer customer address book.

Do not build unnecessary CRM/address-book scope.

==================================================
25. FULFILLMENT PLAN INTEGRATION
==================================================

Checkout invokes PHASE-06 FulfillmentPlanningService.

It must consume:

physical
digital
service
backorder
preorder
multi-source

results.

Checkout must never independently select InventorySource using duplicate logic.

==================================================
26. MIXED CART
==================================================

Required:

Physical + Digital + Service in same Cart.

Expected:

- all participate in pricing/promotion/tax as appropriate
- only physical shippable groups require Shipping
- digital/service groups remain fulfillment groups
- physical shipping rate excludes digital/service quantity/weight
- Checkout remains one coherent session

==================================================
27. SHIPPING QUOTE
==================================================

Checkout invokes PHASE-06 ShippingRateEngine only when physical shipping is required.

Handle:

SUCCESS
NO_SHIPPING_REQUIRED
NO_METHOD_AVAILABLE
DESTINATION_RESTRICTED
UNFULFILLABLE_ITEMS
PROVIDER_FAILURE

Do not collapse them into generic "shipping unavailable".

==================================================
28. SHIPPING METHOD SELECTION
==================================================

Checkout persists selected ShippingRate choice/reference.

Do not trust client amount.

Client selects:

method/service identifier

Server re-resolves/requotes and verifies it is currently eligible.

Persist selected quote snapshot:

- method id/code
- carrier/service
- original amount
- final amount
- currency
- breakdown
- estimate
- quote fingerprint/version
- quoted_at

==================================================
29. STALE SHIPPING QUOTE
==================================================

Define quote freshness.

Before checkout confirmation:

revalidate or requote.

Provider-calculated quotes may expire quickly.

Do not use indefinitely stale carrier quote.

==================================================
30. LOCAL PICKUP
==================================================

Checkout must support selecting Local Pickup as shipping/fulfillment choice.

Validate stock/source again through Fulfillment/Shipping contracts.

Do not bypass Inventory.

==================================================
31. INVENTORY AVAILABILITY
==================================================

Cart browsing/add should NOT necessarily reserve stock.

At Cart stage:

availability check may be advisory/validated.

At Checkout progression:

use PHASE-05 Inventory reservation contracts.

Document exact reservation trigger.

Preferred:

create/refresh reservations when Checkout reaches an appropriate confirmed
inventory-sensitive state, not merely when anonymous user views cart.

==================================================
32. INVENTORY RESERVATION ORCHESTRATION
==================================================

Checkout must orchestrate PHASE-05 reservation service.

Do NOT modify StockItem directly.

Required:

reserve
release
expire
refresh/replace if policy permits
commit deferred to future Order/payment handoff unless architecture requires a handoff token

Checkout abandonment/expiration releases reservations.

==================================================
33. MULTI-SOURCE RESERVATIONS
==================================================

FulfillmentPlan may allocate:

Source A qty 6
Source B qty 4

Checkout reservation must preserve source-level allocation.

Do not collapse to aggregate product reservation.

==================================================
34. RESERVATION ATOMICITY
==================================================

Critical checkout invariant:

A checkout cannot declare inventory secured unless all required stock reservations succeed.

Use transaction/orchestration compensation.

Example:

Group A reserved
Group B fails

Expected:

Group A reservation released/rolled back
Checkout reports structured inventory failure

No partial invisible reservation leakage.

==================================================
35. RESERVATION IDEMPOTENCY
==================================================

Retrying same Checkout reservation operation must not double-reserve.

Use explicit checkout operation idempotency key.

Reuse a generic platform idempotency abstraction if available.

Do not couple Checkout to Inventory's internal implementation class unless it is an approved shared contract.

==================================================
36. RESERVATION EXPIRATION
==================================================

Checkout reservation TTL must be explicit.

Store expires_at.

Checkout knows reservation IDs/references.

When checkout expires:

release reservations idempotently.

Add scheduled cleanup command/job foundation.

==================================================
37. CHECKOUT SESSION
==================================================

Create CheckoutSession.

Conceptual fields:

id
uuid/token
tenant_id
cart_id
customer/user id
store_id
market_id
channel_id
currency
locale
state
contact snapshot
shipping address snapshot
billing address snapshot
fulfillment snapshot/reference
selected shipping quote
pricing snapshot
tax snapshot
promotion snapshot
reservation references
expires_at
version
timestamps

Sensitive data must be handled appropriately.

==================================================
38. CHECKOUT STATE MACHINE
==================================================

Use explicit transitions.

Conceptual states:

created
customer_info_ready
address_ready
fulfillment_ready
shipping_ready
inventory_reserved
review_ready
ready_for_order
expired
cancelled
failed

Exact state names via ADR.

Do NOT allow generic:

PATCH status = arbitrary string.

==================================================
39. STATE TRANSITION VALIDATION
==================================================

Every transition validates required prerequisites.

Example:

Cannot enter shipping_ready without:
- physical fulfillment if needed
- valid destination
- eligible selected shipping rate

Cannot enter inventory_reserved without all reservations.

Cannot enter ready_for_order without:
- pricing current
- promotion current
- taxes current
- inventory secured if required
- shipping selected if required
- customer data valid

==================================================
40. READY-FOR-ORDER HANDOFF
==================================================

PHASE-07 must produce typed immutable-ready result:

CheckoutReadyResult / OrderDraftData

Contains everything future Order creation needs:

- tenant/context
- customer/contact
- address snapshots
- cart lines
- pricing snapshot
- promotion/coupon applications
- tax snapshot
- fulfillment groups
- shipping selection
- reservation references
- totals
- currency
- idempotency/reference

DO NOT create Order in PHASE-07 unless Master Plan explicitly places Order creation here.

Default:
future Phase consumes this handoff.

==================================================
41. CHECKOUT RECALCULATION
==================================================

Any meaningful change invalidates downstream state.

Examples:

quantity change
address change
shipping choice
coupon
Market
currency

Recalculate affected:

pricing
promotions
tax
fulfillment
shipping
reservations

Do not keep stale "ready_for_order" state after cart mutation.

==================================================
42. CART VERSIONING
==================================================

Checkout should know which Cart version it evaluated.

Use optimistic version/update timestamp/hash.

If Cart changes:
Checkout becomes stale and must recalculate.

==================================================
43. CONCURRENT CART UPDATE
==================================================

Protect against lost updates.

Example:
two clients change quantity simultaneously.

Use optimistic locking/version or transactional lock according to ADR.

Do not silently overwrite newer cart state.

Add concurrency test with PostgreSQL.

==================================================
44. CHECKOUT CONCURRENCY
==================================================

Two simultaneous attempts to advance/finalize same Checkout must not:

- double reserve
- duplicate coupon hold
- create conflicting shipping snapshots
- produce inconsistent totals

Use row locking/CAS/idempotency as appropriate.

Add real PostgreSQL concurrency harness if required.

==================================================
45. CHECKOUT IDEMPOTENCY
==================================================

Mutation-sensitive endpoints:

- create checkout from cart
- apply coupon
- reserve inventory
- select shipping if external side effects later exist
- mark ready_for_order

must support safe retries where appropriate.

Use durable operation key + result replay architecture.

Do not invent unsafe message-based duplicate detection.

==================================================
46. CHECKOUT TOTALS
==================================================

Typed total structure:

merchandise_subtotal
line_discount_total
cart_discount_total
shipping_original
shipping_discount
shipping_final
tax_total
grand_total

Potential future:

duties
fees
store credit
gift card
tip

Do not overload one ambiguous `total`.

==================================================
47. TOTAL INVARIANT
==================================================

Define exact reconciliation.

Conceptually:

merchandise
- discounts
+ shipping
+ taxes
+ fees
= grand total

All Money exact.

Add invariant tests.

==================================================
48. ZERO / NEGATIVE TOTAL
==================================================

Checkout totals must never become accidentally negative.

Promotion cannot reduce eligible amount below zero unless approved business logic explicitly allows credits.

Validate exact boundaries.

==================================================
49. FREE SHIPPING
==================================================

PHASE-04 promotion FreeShipping + PHASE-06 Shipping integration must now function through Checkout.

Shipping restrictions remain authoritative.

Free shipping does not make an ineligible method valid.

==================================================
50. DIGITAL-ONLY CHECKOUT
==================================================

Required:

Digital-only Cart:
- Checkout requires no physical ShippingDestination unless product capability says otherwise
- no ShippingRate quote
- no carrier call
- no physical Inventory reservation if product capability does not require stock
- can still reach ready_for_order

Use NO_SHIPPING_REQUIRED.

==================================================
51. PHYSICAL-ONLY CHECKOUT
==================================================

Requires:

fulfillment
shipping destination
shipping quote/selection
inventory reservation

before ready_for_order.

==================================================
52. MIXED CHECKOUT
==================================================

Physical + Digital:

physical:
shipping + reservation

digital:
no physical shipping

single Checkout totals/handoff.

==================================================
53. SERVICE / BOOKING FOUNDATION
==================================================

Checkout must remain capability-driven.

Service/Booking may require future scheduling but not physical shipping.

Do not implement booking engine here.

Return capability-specific unresolved prerequisite if future phase is needed rather than fake success.

==================================================
54. SUBSCRIPTION FOUNDATION
==================================================

Subscription products may reach Checkout but actual recurring billing is out of scope.

Checkout can produce subscription purchase metadata in handoff.

Do not build billing engine.

==================================================
55. TOP-UP / LICENSE FOUNDATION
==================================================

Checkout accepts these product types as capability-defined non-physical purchases.

Do not execute top-up or consume serial/license inventory in PHASE-07 unless Master Plan explicitly says so.

Preserve required fulfillment metadata for future execution.

==================================================
56. BUNDLE / CONFIGURABLE
==================================================

Respect Catalog resolved components/options.

CartLine must preserve configuration identity.

Fulfillment/Shipping uses Catalog-derived capability.

Do not reconstruct bundles independently in Checkout.

==================================================
57. PRICE CHANGES DURING CHECKOUT
==================================================

Before ready_for_order:

re-price.

If price changed:
return structured result and updated totals.

Do not silently finalize old price.

Optional policy:
require explicit customer acknowledgment if configured.

Document.

==================================================
58. STOCK CHANGES DURING CHECKOUT
==================================================

If availability changes before reservation:
re-plan fulfillment.

If selected source cannot satisfy:
attempt deterministic re-plan through Fulfillment.
Requote shipping if source/package changes.

Do not silently reserve another source while keeping stale shipping quote.

==================================================
59. SHIPPING CHANGES AFTER REPLAN
==================================================

Any FulfillmentPlan source/package change may invalidate selected ShippingRate.

Clear/requote/reselect according to deterministic policy.

==================================================
60. TAX CHANGES AFTER ADDRESS
==================================================

Address changes invalidate taxes.

Shipping destination changes may invalidate:
- zone
- shipping rate
- taxes
- fulfillment restrictions

Recalculate.

==================================================
61. COUPON CHANGES
==================================================

Coupon apply/remove invalidates:

pricing/promotion
shipping free-shipping benefit
tax if taxable base changes
grand total

Recalculate deterministically.

==================================================
62. CART EXPIRATION
==================================================

Expired Cart:
- cannot start new Checkout
- cannot mutate normally
- cleanup safe

Define expiration policy.

Guest carts may have shorter TTL.

==================================================
63. CHECKOUT EXPIRATION
==================================================

Expired Checkout:

- transitions once
- reservations released idempotently
- cannot become ready_for_order
- selected shipping quote invalid
- future payment cannot start

Provide scheduled cleanup command/job.

==================================================
64. PERSONAL DATA SECURITY
==================================================

Checkout contains PII.

Requirements:

- tenant scoped
- avoid unnecessary logs
- no full address in structured error logs
- no email/phone secrets in debug diagnostics unless explicitly masked
- authenticated/guest authorization
- secure opaque Checkout token
- no cross-customer access

==================================================
65. GUEST CHECKOUT SECURITY
==================================================

Guest checkout access must use secure token separate from cart database ID.

Token should be revocable/rotatable if appropriate.

Do not expose guest Checkout by sequential ID.

==================================================
66. API
==================================================

Create versioned APIs conceptually:

/api/v1/cart
/api/v1/cart/lines
/api/v1/cart/lines/{id}
/api/v1/cart/coupon
/api/v1/cart/merge

/api/v1/checkout
/api/v1/checkout/{id}
/api/v1/checkout/{id}/customer
/api/v1/checkout/{id}/shipping-address
/api/v1/checkout/{id}/billing-address
/api/v1/checkout/{id}/shipping-rates
/api/v1/checkout/{id}/shipping-selection
/api/v1/checkout/{id}/coupon
/api/v1/checkout/{id}/reserve
/api/v1/checkout/{id}/recalculate
/api/v1/checkout/{id}/ready
/api/v1/checkout/{id}/cancel

Exact REST/state-action design via ADR.

Do NOT expose arbitrary status patching.

==================================================
67. CART API
==================================================

Cart mutation requests may include:

product_id
variant_id
quantity
configuration/options

Do NOT accept:

price
tax
discount
is_shippable
product_type
inventory_available

as trusted truth.

==================================================
68. CHECKOUT API OUTPUT
==================================================

Return typed structured representation:

state
cart version
customer/contact readiness
addresses
fulfillment groups
shipping requirement
available shipping rates
selected shipping
reservations
pricing/totals
tax
promotion
coupon
warnings
blocking errors
expires_at

Never expose internal provider credentials.

==================================================
69. API ERROR MODEL
==================================================

Structured domain errors:

CART_EXPIRED
CART_STALE
PRODUCT_UNAVAILABLE
INVALID_QUANTITY
PRICE_CHANGED
INVENTORY_UNAVAILABLE
FULFILLMENT_CHANGED
NO_SHIPPING_METHOD
SHIPPING_PROVIDER_FAILURE
SHIPPING_QUOTE_EXPIRED
COUPON_INVALID
COUPON_EXPIRED
TAX_CALCULATION_FAILED
CHECKOUT_EXPIRED
INVALID_STATE_TRANSITION

Do not expose raw exceptions.

==================================================
70. CUSTOMER CART OWNERSHIP
==================================================

Authenticated user may access only own Cart unless staff permission.

Guest token cannot access authenticated user's Cart.

Tenant boundary first.

==================================================
71. CONTROL CENTER
==================================================

PHASE-07 does not require staff editing customer checkout arbitrarily.

Provide diagnostics/observability screens if useful:

- active carts
- abandoned carts
- active checkout sessions
- checkout state
- reservation references
- recalculation diagnostics
- blocking reason
- expiration status

Read-only by default.

Any management mutation requires explicit permission.

==================================================
72. RBAC
==================================================

Possible permissions:

cart.inspect
cart.manage
checkout.inspect
checkout.manage
checkout.diagnostics
checkout.reservations.view
checkout.reservations.release

Customer Cart APIs use ownership, not staff RBAC.

Do not role-name check.

==================================================
73. AUDIT
==================================================

Audit high-value transitions:

checkout created
coupon applied/removed
shipping selected
reservation acquired/released
checkout expired/cancelled
ready_for_order produced
staff override if any

Do not flood audit with every quantity preview.

Do not log PII unnecessarily.

==================================================
74. EVENTS
==================================================

Typed events conceptually:

CartCreated
CartLineAdded
CartLineUpdated
CartLineRemoved
CartMerged
CartExpired

CheckoutCreated
CheckoutRecalculated
CheckoutShippingSelected
CheckoutInventoryReserved
CheckoutReservationReleased
CheckoutReadyForOrder
CheckoutExpired
CheckoutCancelled

Avoid event noise.

==================================================
75. OBSERVABILITY
==================================================

Track:

cart recalculation latency
pricing latency
promotion latency
tax latency
fulfillment planning latency
shipping quote latency
reservation latency/failure
checkout state transition failure
expired checkout count
abandoned cart count

No raw PII/secrets.

==================================================
76. CACHE
==================================================

Do not over-cache mutable Cart/Checkout state.

Pricing/catalog reference data may use existing caching.

Correctness > cache.

==================================================
77. DATABASE DESIGN
==================================================

Potential tables:

carts
cart_lines
cart_coupons if required
checkout_sessions
checkout_addresses / JSON snapshots depending ADR
checkout_shipping_selections
checkout_reservations / reservation references
checkout_operation_keys if no generic idempotency platform abstraction exists

Do not blindly create all.

Use normalized tables where querying/integrity matters and JSON only for immutable snapshots/metadata.

==================================================
78. TENANT INTEGRITY
==================================================

DB/domain constraints should prevent:

Tenant A Cart → Tenant B Store
Tenant A CartLine → Tenant B Product
Tenant A Checkout → Tenant B Cart
Tenant A selected ShippingMethod
Tenant A reservation reference
Tenant A customer

Use composite constraints where practical + domain validation.

==================================================
79. CART UNIQUE ACTIVE POLICY
==================================================

If one active Cart per customer/context:

enforce at DB level where PostgreSQL permits using partial unique index.

Guest policy similarly documented.

Do not rely only on application race check.

==================================================
80. CART LINE INTEGRITY
==================================================

Prevent duplicate merge-signature lines when business policy says they should merge.

Use deterministic unique index where practical.

==================================================
81. CHECKOUT ONE-ACTIVE-PER-CART POLICY
==================================================

Define whether Cart may have multiple CheckoutSessions.

Preferred:
one active non-terminal checkout per Cart.

Enforce race-safely.

==================================================
82. CHECKOUT SNAPSHOT IMMUTABILITY
==================================================

Once ready_for_order:

critical snapshots should be immutable.

Further Cart mutation should not alter ready Checkout.

If user wants change:
invalidate/create new Checkout according to ADR.

==================================================
83. IDEMPOTENT READY HANDOFF
==================================================

Retrying:

POST checkout/{id}/ready

with same idempotency key must produce same CheckoutReadyResult.

No duplicate reservation/coupon effects.

==================================================
84. FUTURE ORDER CONTRACT
==================================================

Create explicit interface/DTO consumed by future Order module.

Do NOT import future Order Eloquent model.

Example:

CheckoutReadyResult
OrderDraftData

Document ownership.

==================================================
85. PAYMENT PREPARATION BOUNDARY
==================================================

Checkout may expose:

payable amount
currency
customer
order-draft reference

but must NOT:

authorize
capture
create PaymentIntent
store card details

Payment phase later.

==================================================
86. ZERO-TOTAL CHECKOUT
==================================================

A fully discounted/free digital purchase may yield grand_total = 0.

Checkout should still be valid.

Do not require payment for zero total.

Future Order phase can decide payment bypass.

==================================================
87. SHIPPING RATE PROVIDER FAILURE
==================================================

If some providers fail but valid rates remain:
Checkout can continue.

If only provider failures remain:
structured blocked state.

Do not transform into generic validation error.

==================================================
88. BACKORDER / PREORDER
==================================================

Checkout must respect PHASE-05/06 readiness.

If policy permits backorder/preorder:
may continue with structured readiness.

Do not treat every zero ATS as hard failure.

Handoff must preserve readiness/expected date metadata where available.

==================================================
89. RESERVATION FOR BACKORDER
==================================================

Do not create fake stock reservation against unavailable physical stock if Inventory policy represents backorder separately.

Follow PHASE-05 reservation semantics exactly.

==================================================
90. RESERVATION RELEASE SAFETY
==================================================

Release must be:

idempotent
tenant scoped
Checkout-owned
safe under concurrent expiration/cancel.

No arbitrary reservation ID release by client.

==================================================
91. VALIDATION OWNERSHIP
==================================================

Checkout orchestration may coordinate validations, but source modules own truth.

Examples:

Catalog → purchasability
Pricing → price
Promotions → discounts
Tax → tax
Inventory → stock/reservations
Fulfillment → plan
Shipping → rates

Checkout must not recreate these algorithms.

==================================================
92. NO SILENT FALLBACKS
==================================================

Never silently:

change Store
change Market
change Channel
change Currency
select Shipping Method
select InventorySource
remove unavailable item
change quantity
drop coupon

Return structured state/error requiring explicit behavior.

==================================================
93. LOCALIZATION
==================================================

Customer-facing errors use translation keys.

No hardcoded ar/en.

RTL/LTR compatible.

Checkout stores explicit locale.

==================================================
94. TIMEZONE
==================================================

All timestamps UTC.

Display using explicit context timezone.

Coupon validity, reservation expiry, checkout expiry must be timezone-safe.

==================================================
95. SECURITY SOURCE AUDIT
==================================================

Before final report search:

?? 1
unscoped findOrFail
trusted price
trusted discount
trusted tax
trusted is_shippable
trusted inventory
float money
Cart cross-tenant lookup
Checkout cross-tenant lookup
raw PII logging
arbitrary status patch
direct StockItem mutation
direct Shipping/Promotion/Tax reimplementation
Order/Payment implementation

Fix violations.

==================================================
96. REQUIRED CART TESTS
==================================================

Test:

guest cart creation
authenticated cart creation
tenant isolation
customer ownership
secure guest token
add line
update quantity
remove line
same configuration merge
different configuration separate
invalid product
cross-tenant product
invalid variant
product not purchasable
context mismatch
cart expiration
concurrent line update
guest → customer merge
merge conflict

==================================================
97. PRICING TESTS
==================================================

Test:

server price wins over request
price recalculation
price change detection
multi-currency
exact arithmetic
promotion
coupon apply/remove
coupon invalid/expired
promotion recalculated after quantity change
FreeShipping benefit propagation

==================================================
98. TAX TESTS
==================================================

Test:

tax calculation integration
address change invalidates tax
shipping tax hook
tax class ownership
no Checkout-local percentage calculations

==================================================
99. FULFILLMENT TESTS
==================================================

Test:

physical
digital
service
mixed
single source
multi-source
backorder
preorder
unavailable
fulfillment replan after stock change

==================================================
100. SHIPPING TESTS
==================================================

Test:

NO_SHIPPING_REQUIRED
normal shipping
local pickup
restricted destination
no method
provider partial failure
provider total failure
selected quote validation
quote stale
source/package change invalidates selection
digital quantity/weight never affects shipping

==================================================
101. RESERVATION TESTS
==================================================

Test:

all sources reserve successfully
one source fails → entire checkout reservation fails safely
no leaked partial reservation
same idempotency key does not double reserve
concurrent reservation attempts
expiration release
cancel release
repeat release safe
backorder behavior

Use real PostgreSQL concurrency where necessary.

==================================================
102. CHECKOUT STATE TESTS
==================================================

Test all valid transitions.

Test invalid transitions.

Cannot ready without required:

customer/contact
address
shipping
pricing
tax
reservation

depending on product capabilities.

Digital-only path must not require physical shipping.

==================================================
103. READY HANDOFF TEST
==================================================

CheckoutReadyResult must contain exact immutable snapshot.

Retry same idempotency key:
same result.

No Order created.

No Payment created.

==================================================
104. PURITY TESTS
==================================================

Cart pricing preview must not mutate:

Inventory
Orders
Payments

Shipping quote remains side-effect free.

Fulfillment plan remains side-effect free.

Reservation mutation only occurs at explicit Checkout reservation step.

==================================================
105. API TESTS
==================================================

Test:

guest auth token
customer ownership
Sanctum where applicable
Tenant context
Store/Market/Channel context
IDOR
validation
structured error payload
state actions
idempotency
no business truth trusted from client

==================================================
106. CONCURRENCY HARNESS
==================================================

Where PHPUnit process isolation is insufficient, create PostgreSQL harnesses.

At minimum prove:

- concurrent Cart quantity update does not lose data silently
- concurrent Checkout reservation does not double-reserve
- concurrent checkout creation for same Cart respects active-checkout policy
- same ready idempotency key produces one semantic result

==================================================
107. CONTROL CENTER TESTS
==================================================

If diagnostics UI implemented:

RBAC
tenant isolation
PII masking
read-only default
reservation release explicit permission

==================================================
108. ADRS REQUIRED
==================================================

Create ADRs from next available number after PHASE-06.

At minimum:

1. Cart/Checkout domain ownership
2. Cart identity and guest-token model
3. Active Cart uniqueness policy
4. CartLine merge identity/signature
5. Cart context binding
6. Pricing snapshot/recalculation strategy
7. Coupon lifecycle
8. CheckoutSession state machine
9. Address snapshot strategy
10. Fulfillment integration
11. Shipping quote selection/freshness
12. Inventory reservation trigger/lifecycle
13. Reservation atomicity/idempotency
14. Cart/Checkout concurrency
15. CheckoutReadyResult / future Order handoff
16. Checkout expiration/cleanup
17. PII/security model

Use next available ADR numbers.

==================================================
109. STRICTLY OUT OF SCOPE
==================================================

DO NOT implement:

- Order Eloquent domain
- Order management UI
- Payment providers
- PaymentIntent
- charge/capture/refund
- Financial Ledger
- vendor settlement
- commissions
- payouts
- supplier PO
- dropship execution
- shipment execution
- label purchase
- returns/RMA
- subscription recurring billing
- top-up fulfillment
- license delivery
- gift card wallet
- POS sale
- customer mobile app
- vendor app
- AI agent execution
- MCP commerce execution

Do not begin next phase.

==================================================
110. QUALITY GATES
==================================================

Run:

php -d memory_limit=512M vendor/bin/pest

php -d memory_limit=512M vendor/bin/phpstan analyse \
-a phpstan-bootstrap.php \
--level=8 \
--memory-limit=512M

./vendor/bin/pint --test

npm run build

composer audit

npm audit --audit-level=high

Also:

php artisan migrate:fresh --seed

Rollback ONLY PHASE-07 migrations.

Clean re-migration.

Run dedicated PostgreSQL concurrency harnesses.

==================================================
111. FINAL SOURCE-LEVEL AUDIT
==================================================

Explicitly verify:

- no tenant fallback
- no cross-tenant Cart/Checkout IDOR
- no trusted client pricing
- no trusted is_shippable
- no client stock truth
- no binary float Money
- no direct Inventory stock mutation
- no Shipping duplication
- no Promotion duplication
- no Tax duplication
- no arbitrary Checkout state update
- no PII leakage
- no Order implementation
- no Payment implementation

==================================================
112. FINAL REPORT
==================================================

Return:

PHASE-07 COMPLETION REPORT

including:

1. Phase status.
2. Git commit hash.
3. Cart module architecture.
4. Checkout module architecture.
5. Dependency graph.
6. Cart lifecycle.
7. Guest/customer identity model.
8. Cart line merge strategy.
9. Context binding.
10. Product capability integration.
11. Pricing orchestration.
12. Promotion/coupon integration.
13. Tax integration.
14. Fulfillment integration.
15. Shipping integration.
16. NO_SHIPPING_REQUIRED handling.
17. Mixed physical/digital handling.
18. Reservation trigger/lifecycle.
19. Multi-source reservation behavior.
20. Reservation atomicity.
21. Reservation idempotency.
22. Checkout state machine.
23. Checkout expiration.
24. Address/contact snapshot model.
25. Totals reconciliation.
26. Cart/Checkout concurrency strategy.
27. CheckoutReadyResult handoff.
28. API route list.
29. Guest/customer security.
30. RBAC.
31. Audit/events/observability.
32. Database tables/indexes/constraints.
33. ADR list.
34. PHASE-07-specific tests.
35. Total test count/assertions.
36. PostgreSQL concurrency harness results.
37. Quality/security gates.
38. Remaining risks/limitations.
39. Explicit confirmation no Order domain was implemented.
40. Explicit confirmation no Payment functionality was implemented.
41. Explicit confirmation next phase was NOT started.

Then STOP.

DO NOT begin the next phase.