Read PROJECT_MASTER_PLAN.md fully before doing anything.

PHASE-05 is CLOSED & ACCEPTED.

Accepted PHASE-05 final commit:

1f5d445

We are now starting:

PHASE-06 — SHIPPING, FULFILLMENT, ZONES & CARRIERS

Before implementation:

1. Read:
   - PROJECT_MASTER_PLAN.md
   - all accepted ADRs
   - PHASE-05 specification and final closure state
   - current Inventory module implementation
   - Catalog and Pricing contracts relevant to shipping eligibility
   - Context system
   - module dependency registry

2. Load all relevant Skills, especially:
   - project-governance
   - architecture-boundaries
   - commerce-domain
   - fulfillment-dropshipping
   - multi-tenancy
   - multi-store-context
   - postgresql-data-design
   - money-ledger
   - localization-markets-rtl
   - api-webhooks
   - security-hardening
   - testing-quality
   - performance-observability
   - documentation-adr

3. Run the current complete project quality baseline before modifying code.

4. If a defect from PHASE-05 materially blocks Shipping/Fulfillment correctness,
   STOP and report it.

5. Create the authoritative phase specification first:

docs/phases/PHASE-06-SHIPPING-FULFILLMENT-ZONES-CARRIERS.md

Then execute ONLY PHASE-06.

Do NOT modify PROJECT_MASTER_PLAN.md.

==================================================
PHASE OBJECTIVE
==================================================

Build the complete first-party shipping and fulfillment foundation for Hyper Commerce.

PHASE-06 must establish:

- Shipping domain
- Fulfillment domain foundation
- Shipping Zones
- Shipping Methods
- Shipping Rates
- Carrier abstraction
- Carrier service abstraction
- Package / Parcel concepts
- Shipment planning foundation
- Fulfillment Locations / InventorySource integration
- Fulfillment eligibility
- Multi-source fulfillment planning
- Split fulfillment architecture
- Shipping address / destination DTO boundary
- Shipping rate calculation engine
- Flat-rate shipping
- Free shipping
- Table / rule-based shipping
- Weight-based shipping
- Price-based shipping
- Quantity-based shipping
- Location-based shipping
- Pickup / Local Pickup
- Local Delivery foundation
- Digital / non-shippable handling
- Shipment lifecycle foundation
- Tracking abstraction
- Label/purchase abstraction for future carrier plugins
- First-party carrier/provider registry
- Provider/plugin extension contracts
- Shipping restrictions
- Package constraints
- Delivery estimate foundation
- Shipping taxes hook
- Promotion FreeShipping benefit integration
- API
- Control Center
- RBAC
- Auditing
- events
- concurrency / idempotency where needed
- comprehensive tests

IMPORTANT:

PHASE-06 DOES NOT IMPLEMENT:

- Cart persistence
- Checkout orchestration
- Customer Order creation
- Payment processing
- Financial Ledger
- Vendor settlement
- Purchase Orders
- Dropshipping purchasing
- Supplier order placement
- real carrier provider integrations unless explicitly required by Master Plan
- actual payment for shipping labels
- customer checkout UI
- returns/RMA
- full order fulfillment execution

PHASE-06 builds the shipping and fulfillment engine that PHASE-07 Checkout and
future Orders/Fulfillment workflows will consume.

==================================================
1. MODULE ARCHITECTURE
==================================================

Determine the correct module boundary based on the existing architecture.

Preferred logical separation:

modules/Shipping/
modules/Fulfillment/

or one Shipping module with a clearly isolated Fulfillment subdomain ONLY if
that matches existing project module conventions.

Shipping owns:

- zones
- methods
- carrier abstractions
- rate calculation
- package rules
- destination eligibility
- shipping services
- delivery estimates
- pickup/local delivery configuration
- tracking/provider contracts

Fulfillment owns:

- fulfillment planning
- fulfillment source eligibility
- allocation of shippable items to InventorySources
- split fulfillment planning
- shipment planning foundation
- fulfillment strategy contracts

Inventory remains owner of:

- physical stock truth
- reservations
- warehouses
- InventorySources
- ATS
- stock allocation/reservation mechanics

Shipping/Fulfillment MUST NOT duplicate inventory balances.

==================================================
2. DEPENDENCY BOUNDARIES
==================================================

Expected dependency direction:

Shipping
→ Platform contexts / Markets / Stores
→ Catalog capability contracts where needed
→ Pricing money contracts where shipping rates require money

Fulfillment
→ Inventory contracts
→ Shipping contracts
→ Catalog capability contracts

Inventory MUST NOT depend on Shipping or Fulfillment.

Catalog MUST NOT depend on Shipping.

Pricing MUST NOT depend on Shipping implementation.

Avoid circular dependencies.

Document dependency graph in ADR.

==================================================
3. SHIPPING ADDRESS / DESTINATION VALUE OBJECT
==================================================

Do NOT create Customer Address persistence or Checkout models yet.

Create neutral immutable DTO / Value Object for shipping destination.

Conceptually:

ShippingDestination

Fields may include:

- country_code
- administrative_area / region / state
- city
- postal_code
- address_line_1 optional where required
- address_line_2 optional
- latitude/longitude optional future hook

Shipping rate engine must consume a destination DTO rather than Checkout models.

Country codes must integrate with existing Country/reference data.

Normalize postal codes and country codes appropriately.

==================================================
4. SHIPPING ZONES
==================================================

Create first-class Shipping Zones.

A Shipping Zone groups destinations.

Examples:

- Switzerland
- EU
- Germany
- DACH
- North America
- Worldwide
- Zurich postal codes
- custom regional zone

Zone rules should support:

- Countries
- Regions/states
- Postal code exact matches
- Postal code prefixes
- Postal code ranges where sensible
- explicit exclusions
- future geo-radius extension

Do not hardcode countries into shipping logic.

==================================================
5. SHIPPING ZONE SCOPING
==================================================

Zones must support appropriate Commerce context:

- Tenant
- Store
- Market
- Channel where required

Define deterministic eligibility/precedence.

Potential model:

Tenant Shipping Zone
→ Store assignment
→ Market assignment
→ Channel assignment

Document whether zones are shared across Stores or Store-specific.

Avoid duplicated zone records for every Store if assignments solve the problem.

==================================================
6. ZONE MATCHING ENGINE
==================================================

Create dedicated:

ShippingZoneMatcher

or equivalent.

Input:

ShippingDestination
ShippingContext

Output:

matching zone(s)

Define deterministic precedence when multiple zones match.

Preferred concept:

most-specific match wins

Example specificity:

postal-code rule
> region/state
> country
> broad/global zone

Do not depend on database row creation order.

Add conflict tests.

==================================================
7. SHIPPING METHODS
==================================================

Create extensible ShippingMethod domain.

Examples:

- flat_rate
- free_shipping
- table_rate
- weight_based
- order_value_based
- quantity_based
- local_pickup
- local_delivery
- carrier_calculated
- future plugin-defined method

Do NOT use a closed DB enum preventing plugins.

Use registry/contracts.

Shipping Methods should support:

- Tenant
- code
- localized name/description
- active/inactive
- zone associations
- Store/Market/Channel eligibility
- priority/sort
- rate calculator strategy
- min/max constraints
- package constraints
- future carrier service binding

==================================================
8. SHIPPING METHOD REGISTRY
==================================================

Create an extensible ShippingMethodTypeRegistry or equivalent.

Core types register first-party calculators.

Plugins can register future calculators without schema migration.

Do NOT allow arbitrary public API strings to execute unknown rate logic.

==================================================
9. MONEY MODEL FOR SHIPPING
==================================================

Use existing Phase-04 money architecture.

Never binary float.

Shipping rates must use:

- MoneyValue / Brick Money abstraction already approved
- integer minor amounts where persisted
- explicit currency

Do not build a second Money implementation.

Shipping rate calculations must support currencies appropriately.

==================================================
10. SHIPPING RATE QUOTE CONTRACT
==================================================

Create a neutral request DTO.

Conceptually:

ShippingRateRequest

Contains:

- Tenant
- Store
- Market
- Channel
- destination
- currency
- shipment/package candidate(s)
- shippable item lines

Each line may contain neutral data such as:

- product_id
- variant_id
- quantity
- unit weight
- dimensions
- shipping class
- inventory source if preselected
- monetary subtotal where required by rules

Do NOT pass Cart or Order models.

==================================================
11. SHIPPING RATE RESULT
==================================================

Return typed result objects.

Conceptually:

ShippingRateQuote

Fields:

- method_id
- method code
- service code
- carrier code optional
- title
- amount MoneyValue
- tax metadata hook
- estimated min days
- estimated max days
- delivery date range optional
- source / package references
- warnings
- restrictions
- metadata for extensions

Never return arbitrary untyped arrays from core domain if a typed DTO is reasonable.

==================================================
12. FLAT RATE SHIPPING
==================================================

Implement first-party Flat Rate calculator.

Support configuration such as:

- fixed amount
- optional per-item amount
- optional per-package amount
- min/max charge where appropriate

Use exact Money arithmetic.

==================================================
13. FREE SHIPPING
==================================================

Implement free shipping method.

Support eligibility based on configured rules.

Also integrate the structured FreeShipping benefit produced by PHASE-04 Promotions.

Do NOT make Promotions depend on Shipping.

Shipping may consume a neutral promotion benefit DTO/contract.

Ensure FreeShippingAction from Promotions can be interpreted by PHASE-06 without
modifying Promotion internals incorrectly.

==================================================
14. TABLE RATE SHIPPING
==================================================

Create commercial-grade table/rule-based shipping foundation.

Rules may evaluate:

- destination zone
- weight
- order/subtotal amount
- quantity
- package count
- shipping class
- Store
- Market
- Channel

Rules must be deterministic and strongly typed.

Do NOT store executable arbitrary PHP/SQL/expressions in DB.

Use registered condition classes and typed parameters, similar to Promotion rules.

==================================================
15. WEIGHT-BASED SHIPPING
==================================================

Support weight ranges.

Example:

0–1 kg -> CHF 5
1–5 kg -> CHF 9
5–20 kg -> CHF 18

Weight arithmetic must avoid float errors.

Decide precise representation.

Recommended:

Weight Value Object using decimal string / NUMERIC with explicit unit semantics.

Do not assume kg only.

==================================================
16. DIMENSIONS FOUNDATION
==================================================

Create a minimal dimension model/value objects for:

- length
- width
- height
- weight

Support units such as:

Weight:
- g
- kg
- oz
- lb future if needed

Dimensions:
- mm
- cm
- m
- inch future

Use exact decimal representation.

Do not build an unnecessarily complex scientific unit engine.

==================================================
17. SHIPPING PROFILE / SHIPPING CLASS
==================================================

Create extensible product shipping classification foundation.

Examples:

- standard
- oversized
- fragile
- hazardous-restricted placeholder
- refrigerated future
- furniture
- digital/non-shippable
- plugin-defined

Prefer a generic Shipping Profile/Class entity or registry rather than hardcoded booleans.

Catalog integration should be through approved extension boundary.

Do NOT move product ownership into Shipping.

==================================================
18. PRODUCT SHIPPING CAPABILITIES
==================================================

Use Product Type Registry capabilities.

Examples conceptually:

physical:
shippable

digital:
not physically shippable

service:
not shippable

booking:
not physically shippable

bundle:
depends on contained products

print-on-demand:
shippable via future external provider

Do NOT scatter:

if physical
if digital

throughout Shipping.

Use capability contracts.

==================================================
19. PACKAGE / PARCEL DOMAIN
==================================================

Create first-class Package/Parcel DTO/domain model for rate calculation.

Package candidate should include:

- items
- weight
- dimensions
- source
- destination
- package type
- declared value hook
- shipping class constraints

Do not persist Customer Shipment packages unnecessarily yet unless required for
Shipment planning.

Separate:

Package Definition / Package Type
from
Shipment Package instance

==================================================
20. PACKAGE TYPES
==================================================

Allow configured package types.

Examples:

- box
- envelope
- pallet
- custom

Fields:

- dimensions
- max weight
- tare weight
- active status
- tenant scope

Future carrier plugins may define supported package codes.

Do not hardcode to carrier-specific package types.

==================================================
21. PACKING ENGINE FOUNDATION
==================================================

Provide a deterministic packing service foundation.

PHASE-06 does NOT need advanced 3D bin-packing optimization unless explicitly justified.

Minimum support:

- package all items together when valid
- split when max weight/dimension/profile rules require
- separate incompatible shipping classes
- future advanced packing strategy plugins

Create strategy interface.

Do not lock architecture to one packing algorithm.

==================================================
22. SHIPPING RESTRICTIONS
==================================================

Create restriction rules.

Examples:

- Product cannot ship to Country X
- Shipping Class unavailable in Zone Y
- InventorySource cannot fulfill Market Z
- Method disabled for Channel X
- max weight exceeded
- dimensions exceed method limits
- PO box restriction future
- age/restricted goods future policy hook

Return structured rejection reasons.

Do not silently hide all errors internally.

==================================================
23. LOCAL PICKUP
==================================================

Implement first-party Local Pickup method.

Pickup locations can map to:

- Warehouse
- InventorySource
- Store location

Do not duplicate physical address when an existing Warehouse can provide it.

Support:

- enabled/disabled
- pickup instructions
- optional fee
- pickup availability
- Store/Market scope
- future pickup time slots hook

Do NOT implement Booking scheduling.

==================================================
24. LOCAL DELIVERY
==================================================

Implement Local Delivery foundation.

Eligibility may use:

- zone
- postal code
- future distance/radius
- Store / fulfillment location

Support fixed/local rule-based fees.

Do NOT require Google Maps or external geocoding provider in this phase.

Provide future distance-provider contract only if appropriate.

==================================================
25. CARRIER ABSTRACTION
==================================================

Create first-class Carrier contracts.

Carrier != Shipping Method.

Example:

Carrier:
DHL

Services:
DHL Express
DHL Economy

Method presented to customer may bind to one CarrierService.

Create:

CarrierRegistry
CarrierProviderInterface
CarrierServiceDTO

or equivalent.

Do not hardcode DHL/UPS/etc into core domain logic.

==================================================
26. CORE CARRIER CAPABILITIES
==================================================

Carrier contract should prepare for:

- fetch rates
- create shipment
- purchase/create label
- cancel label
- tracking
- delivery estimates
- pickup/dropoff services
- service discovery
- address validation future
- webhook ingestion future

PHASE-06 may implement contracts and mock/manual provider only.

Do NOT integrate paid/real carrier APIs unless explicitly in Master Plan.

==================================================
27. MANUAL / STATIC CARRIER PROVIDER
==================================================

Create a first-party manual/static provider useful for:

- testing
- self-hosted stores
- manually configured carrier services
- environments without external APIs

This ensures carrier-calculated architecture can function without third-party credentials.

==================================================
28. CARRIER CREDENTIAL STORAGE
==================================================

Prepare provider credential architecture.

Requirements:

- encrypted at rest
- tenant scoped
- never returned through normal API
- secrets excluded from logs/audit payloads
- support multiple credentials/accounts per carrier in future

Do not expose plaintext provider secrets.

Use existing secure configuration mechanisms if available.

==================================================
29. CARRIER SERVICE CONFIGURATION
==================================================

Support carrier service bindings:

- provider
- service code
- localized display title
- zone
- Store
- Market
- Channel
- markup / handling fee
- active status
- package restrictions

Markup calculation must use exact Money arithmetic.

==================================================
30. RATE CALCULATION PIPELINE
==================================================

Create explicit pipeline.

Conceptually:

ShippingRateEngine

1. validate ShippingContext
2. resolve shippable items
3. resolve eligible Inventory/Fulfillment sources where appropriate
4. determine packages
5. match destination zones
6. resolve eligible Shipping Methods
7. apply restrictions
8. invoke rate calculators/carrier providers
9. apply configured handling/markup
10. apply FreeShipping benefit
11. produce typed rate quotes
12. sort deterministically

Do not make the engine dependent on Checkout.

==================================================
31. RATE DETERMINISM
==================================================

Same input and same provider response should produce same ordering/result.

Define sorting:

- configured priority
- final amount
- deterministic code/id tie-breaker

Avoid DB insertion-order behavior.

==================================================
32. FULFILLMENT DOMAIN
==================================================

Create Fulfillment planning foundation.

Fulfillment is NOT Shipping.

Fulfillment decides:

"Where/how can these items be fulfilled?"

Shipping decides:

"How can this physical package reach destination?"

Keep separation explicit.

==================================================
33. FULFILLMENT METHOD / MODE
==================================================

Prepare extensible fulfillment modes:

- own_stock
- vendor_stock
- dropship
- 3pl
- print_on_demand
- made_to_order
- digital
- service
- pickup
- future plugin-defined

Do NOT execute supplier orders/dropshipping in PHASE-06.

Create registry/contracts only where needed.

==================================================
34. FULFILLMENT SOURCE ELIGIBILITY
==================================================

Integrate with PHASE-05 InventorySource.

Use Inventory contracts to determine:

- active source
- available stock
- Store eligibility
- Market eligibility
- Channel eligibility
- freshness
- source priority

Do not duplicate these rules.

Shipping/Fulfillment may add destination/shipping eligibility on top.

==================================================
35. FULFILLMENT PLAN DTO
==================================================

Create neutral:

FulfillmentPlan

No Order required.

Plan may contain:

FulfillmentGroup(s)

Each group includes:

- fulfillment mode
- InventorySource
- Warehouse where applicable
- item lines
- package candidates
- shipping method compatibility
- split reason
- warnings

This plan will later be consumed by Checkout/Order creation.

==================================================
36. MULTI-SOURCE FULFILLMENT
==================================================

Support:

Product A -> Source Zurich
Product B -> Source Berlin

One checkout may require multiple fulfillment groups later.

PHASE-06 must be capable of producing split fulfillment plans.

Do not force every shipment to come from one source.

==================================================
37. SPLIT SHIPMENT FOUNDATION
==================================================

One future Order can generate multiple Shipments.

Model architecture accordingly.

Do NOT create Order table.

Possible neutral relationship:

ShipmentPlan / FulfillmentGroup reference key

Future Order integration occurs later.

==================================================
38. SOURCE SELECTION STRATEGY
==================================================

Build deterministic fulfillment source strategy.

Possible criteria:

- inventory availability
- InventorySource priority
- source assignments
- destination eligibility
- shipping method availability
- avoid split if possible
- future cost
- future lead time
- future SLA
- future proximity

PHASE-06 can implement baseline:

1. eligible source
2. enough stock
3. priority
4. minimize split where deterministic

Do not pretend to optimize geographic carrier costs before that capability exists.

==================================================
39. SOURCE + SHIPPING COMPATIBILITY
==================================================

A source may not support every Shipping Method.

Support mappings/restrictions between:

InventorySource
and
ShippingMethod / CarrierService

Examples:

Supplier A only supports DHL
Warehouse B supports pickup
3PL C supports carrier-calculated services

Keep extensible.

==================================================
40. SHIPMENT FOUNDATION
==================================================

Create Shipment domain only if required by the PHASE-06 spec.

If created, it must be neutral and NOT require Order.

Shipment concept may support:

- tenant
- fulfillment/source
- destination snapshot/value object
- status
- carrier
- service
- tracking number
- shipped_at
- delivered_at
- packages
- external_reference

But do not prematurely create Customer Order relationships.

Document whether PHASE-06 uses:
- persistent Shipment
or
- ShipmentPlan only.

==================================================
41. SHIPMENT STATUS
==================================================

If persistent Shipment is introduced, support extensible state machine such as:

- pending
- ready
- label_created
- shipped
- in_transit
- delivered
- failed
- cancelled

Do NOT use arbitrary status updates.

Use explicit transitions.

Do not implement real carrier tracking polling unless supported by provider abstraction.

==================================================
42. TRACKING
==================================================

Create tracking abstraction:

TrackingProvider / CarrierProvider tracking contract.

Tracking result may include:

- tracking_number
- carrier_code
- status
- events
- estimated_delivery
- tracking_url optional provider-generated

Do not scrape carrier websites.

==================================================
43. SHIPPING LABEL FOUNDATION
==================================================

Prepare typed contract for:

CreateLabelRequest
CreateLabelResult

Result may contain:

- external shipment id
- label reference/file URL
- tracking number
- cost
- carrier
- service

Do NOT perform real label purchase unless using a manual/mock provider.

Do not implement financial settlement.

==================================================
44. WEBHOOK EXTENSION
==================================================

Prepare carrier webhook processing contracts.

Future providers can send:

- tracking updates
- delivery updates
- label status

Do not expose generic unauthenticated webhook execution.

Provider webhook signatures must be verifiable by provider adapters when later implemented.

==================================================
45. DELIVERY ESTIMATES
==================================================

Support delivery estimate foundation.

First-party methods can use configured:

- min transit days
- max transit days
- handling days

Use timezone-aware calculations.

Do not promise exact carrier ETA without provider data.

Business-day calendar/holidays can be extension-ready without full calendar engine if not required.

==================================================
46. TIMEZONE HANDLING
==================================================

All timestamps stored canonical UTC.

Warehouse/Source timezone may affect:

- cutoff future
- dispatch dates
- pickup hours future

Display using explicit locale/timezone context.

No implicit server timezone assumptions.

==================================================
47. SHIPPING TAX HOOK
==================================================

Do NOT duplicate Tax engine.

Shipping rate must expose taxable amount/class metadata so existing Tax subdomain can later calculate shipping tax.

If Phase-04 Tax already has a suitable extension contract, use it.

Do not create tax percentages inside Shipping.

==================================================
48. PROMOTION FREE SHIPPING INTEGRATION
==================================================

Phase-04 `FreeShippingAction` already emits a structured benefit.

Integrate through a neutral contract.

Requirements:

- promotion benefit can waive eligible Shipping quote amount
- original amount remains traceable if useful
- applied benefit metadata is returned
- method eligibility still enforced
- free shipping cannot make an otherwise forbidden method eligible

Test this thoroughly.

==================================================
49. SHIPPING COST VS HANDLING FEE
==================================================

Separate concepts:

- carrier/base rate
- shipping method rate
- handling fee
- markup
- promotion discount
- final shipping amount

Avoid one ambiguous `price` field.

Use typed result breakdown.

==================================================
50. CURRENCY CONVERSION
==================================================

If configured shipping rate currency differs from requested checkout currency,
use existing approved CurrencyConversionInterface.

Do NOT implement another exchange-rate mechanism.

Do not silently assume 1:1 conversion.

==================================================
51. MULTI-CURRENCY RATE CONFIGURATION
==================================================

Static/table rates should support either:

- explicit currency-specific amounts
or
- base currency + approved conversion path

Document strategy.

==================================================
52. SHIPPING METHOD AVAILABILITY
==================================================

Methods may be constrained by:

- zone
- Store
- Market
- Channel
- min/max subtotal
- weight
- quantity
- shipping class
- source
- package
- product restrictions

Use typed condition evaluators.

Do not scatter raw condition logic throughout routes.

==================================================
53. SHIPPING METHOD PRIORITY
==================================================

Allow deterministic priority.

Do not use creation timestamp as business priority.

==================================================
54. FREE SHIPPING THRESHOLD
==================================================

First-party Free Shipping may support configured subtotal threshold.

Use MoneyValue.

Do not conflict with Promotion free-shipping benefit.

Distinguish:

Configured Free Shipping Method
vs
Promotion Waived Shipping Cost.

==================================================
55. SHIPPING CLASSES
==================================================

Implement shipping classes/profiles where required.

Potential entities:

shipping_classes
product_shipping_class assignments / Catalog extension

Examples:

standard
oversized
fragile

Do not hardcode business-specific classes permanently.

==================================================
56. DIMENSION / WEIGHT VALIDATION
==================================================

No binary floats.

Use precise decimal/string objects.

Validate:

- weight > 0 where provided
- dimensions >= 0
- unit validity
- conversions deterministic

Add precision tests.

==================================================
57. LOCAL PICKUP INVENTORY INTEGRATION
==================================================

Pickup options should only be offered where a source/location can fulfill the items.

Integrate Inventory availability.

Do not offer pickup from a location with no eligible stock unless backorder/pickup policy explicitly permits it.

==================================================
58. DIGITAL / NON-SHIPPABLE MIX
==================================================

Fulfillment planning must support mixed future checkout:

- physical product
- digital product
- service

Only physical/shippable groups should enter ShippingRateEngine.

Digital/service groups remain valid fulfillment groups but produce no physical shipping requirement.

Do not fail entire plan merely because non-shippable products exist.

==================================================
59. BUNDLES
==================================================

Respect Catalog bundle semantics.

A bundle containing physical child products may require shipping based on its fulfillment/shipping capability.

Do not hardcode assumptions.

Use Catalog capability/relationship services.

==================================================
60. BACKORDER / PREORDER SHIPPING FOUNDATION
==================================================

PHASE-05 can expose backorder/preorder state.

Shipping/Fulfillment should be capable of returning:

- currently fulfillable
- backordered
- preorder / expected later

Do not create Orders or promise actual shipment dates.

Expose structured fulfillment readiness.

==================================================
61. FULFILLMENT STATUS / READINESS
==================================================

Create neutral status/result such as:

- ready
- partial
- backordered
- preorder
- non_physical
- unavailable

Exact naming via ADR.

This is planning, not Order fulfillment execution.

==================================================
62. CONTROL CENTER — SHIPPING
==================================================

Create functional module-owned Livewire UI.

Required:

- Shipping Zones
- Shipping Methods
- Rate Rules / Table Rates
- Shipping Classes / Profiles
- Package Types
- Carriers
- Carrier Services
- Local Pickup configuration
- Local Delivery configuration
- Shipping restrictions
- provider credential/config screen with secrets protected
- rate preview/test tool

Use existing Control Center shell.

Use:
- Livewire
- x-ui wrappers
- Tailwind/daisyUI
- RTL/LTR

Do not custom-design unnecessarily.

==================================================
63. CONTROL CENTER — FULFILLMENT
==================================================

Provide functional view/configuration for:

- fulfillment source eligibility
- source-to-method mappings
- fulfillment strategy settings
- planning preview/test tool
- split fulfillment diagnostics

Do NOT create Order fulfillment UI.

==================================================
64. SHIPPING RATE PREVIEW TOOL
==================================================

Create admin preview UI.

Input:

- destination
- Store/Market/Channel
- product/variant
- quantity
- optional source

Output:

- fulfillment groups
- matched zones
- eligible methods
- rejected methods with reason
- rate breakdown

Useful for diagnostics.

No Checkout required.

==================================================
65. MANAGEMENT API
==================================================

Create authenticated API endpoints under appropriate versioned namespace.

Examples conceptually:

/api/v1/shipping/zones
/api/v1/shipping/methods
/api/v1/shipping/classes
/api/v1/shipping/packages
/api/v1/shipping/carriers
/api/v1/shipping/carrier-services
/api/v1/shipping/rates/quote
/api/v1/shipping/restrictions
/api/v1/shipping/pickup-locations

/api/v1/fulfillment/plan
/api/v1/fulfillment/sources
/api/v1/fulfillment/preview

Use Sanctum + Context + RBAC.

==================================================
66. API RATE QUOTE
==================================================

The quote endpoint must use neutral request DTO.

No Cart ID required.

Example request conceptually:

{
  destination,
  currency,
  store_id,
  market_id,
  channel_id,
  items: [...]
}

Return:

- packages
- fulfillment groups
- eligible methods
- amounts
- estimates
- rejection reasons where appropriate

Do not expose internal provider credentials.

==================================================
67. PUBLIC VS MANAGEMENT DATA
==================================================

Do not expose:

- internal carrier credentials
- provider raw secrets
- private warehouse operational metadata
- source cost
- debug stack traces

Public/customer-facing quote output should expose only necessary shipping information.

==================================================
68. RBAC
==================================================

Create granular permissions such as:

shipping.view
shipping.manage
shipping.zones.view
shipping.zones.manage
shipping.methods.view
shipping.methods.manage
shipping.rates.view
shipping.rates.manage
shipping.carriers.view
shipping.carriers.manage
shipping.credentials.manage
shipping.restrictions.manage
shipping.preview

fulfillment.view
fulfillment.manage
fulfillment.plan
fulfillment.preview

Exact naming may vary if documented.

Use permission/policy checks, not role-name checks.

==================================================
69. AUDIT
==================================================

Audit high-value actions:

- zone create/update/delete/archive
- shipping method config changes
- table rate changes
- restriction changes
- carrier configuration changes
- credentials changes without logging secret
- fulfillment strategy changes
- source-method mapping changes
- package type changes

Audit log must not contain secret credentials.

==================================================
70. EVENTS
==================================================

Create typed domain events where useful.

Examples:

ShippingZoneCreated
ShippingMethodChanged
ShippingRateQuoted
ShippingRateRejected
FulfillmentPlanCreated
FulfillmentSplitDetected
CarrierConfigured

If persistent Shipment exists:

ShipmentCreated
ShipmentStatusChanged
TrackingUpdated

Avoid noisy events for every internal evaluator step.

==================================================
71. OBSERVABILITY
==================================================

Rate calculation diagnostics should be observable.

Track:

- calculator/provider
- latency
- provider timeout
- rejected method reason
- rate count
- fulfillment split count

Do not log credentials or personal address data unnecessarily.

Use structured logs.

==================================================
72. CARRIER TIMEOUTS
==================================================

Carrier providers are external and unreliable.

Contracts must support:

- timeout policy
- retry policy
- provider failure isolation
- partial result behavior

If one carrier fails, other valid methods may still be returned.

Do not fail all ShippingRateEngine results unless policy requires it.

==================================================
73. CIRCUIT BREAKER EXTENSION
==================================================

Design future circuit-breaker/provider-health boundary.

Do not necessarily build full distributed circuit breaker now.

Document extension point.

==================================================
74. PROVIDER ERROR MODEL
==================================================

Use structured provider errors.

Distinguish:

- authentication_error
- timeout
- unavailable_service
- invalid_address
- rate_not_available
- provider_internal_error

Do not leak raw provider messages to customer-facing APIs.

==================================================
75. IDEMPOTENCY
==================================================

Apply idempotency where operations are mutation/retry-sensitive.

Examples if persistent:

- create shipment
- create label
- cancel label

Rate quote itself is read-like and should not create mutable side effects.

Reuse approved platform/inventory idempotency infrastructure only if generic enough;
do not incorrectly couple Shipping to Inventory internals.

If a shared platform abstraction is needed, follow governance/proposal rules rather
than silently moving Inventory code.

==================================================
76. NO LABEL SIDE EFFECTS DURING RATE QUOTE
==================================================

Critical:

ShippingRateEngine must NEVER purchase/create a real label as part of rate quoting.

Rate quote = pure/read-like calculation/provider lookup.

Label creation is explicit future mutation.

==================================================
77. CACHE
==================================================

Static shipping configuration may be cached.

Rate quote caching must be conservative because:

- destination
- items
- currency
- source
- carrier provider response
- promotion
- availability

can change.

Correctness over caching.

Define invalidation.

==================================================
78. SECURITY — PROVIDER CREDENTIALS
==================================================

Credentials must:

- be encrypted
- be hidden from serialization
- never appear in debug payload
- never appear in audit content
- never appear in logs
- require dedicated permission to update

Tests required.

==================================================
79. TENANT ISOLATION
==================================================

Every Shipping/Fulfillment entity must be tenant-safe.

Test:

Tenant A zone
cannot be used by Tenant B method

Tenant A carrier credentials
cannot be read by Tenant B

Tenant A InventorySource
cannot be mapped into Tenant B fulfillment strategy

Tenant A shipping method
cannot be quoted in Tenant B context

Use DB constraints/composite ownership where practical plus domain guards.

==================================================
80. STORE / MARKET / CHANNEL ISOLATION
==================================================

Quote and fulfillment must respect:

- Store
- Market
- Channel

No fallback to arbitrary default Store/Market.

Unresolved required context must fail safely.

==================================================
81. SHIPPING ZONE TESTS
==================================================

Test:

- Country match
- Region match
- Postal exact match
- Postal prefix match
- Postal exclusion
- specificity precedence
- overlapping zones
- Store/Market/Channel eligibility
- Tenant isolation

==================================================
82. SHIPPING RATE TESTS
==================================================

Test:

Flat Rate
Free Shipping
Table Rate
Weight Rate
Subtotal Rate
Quantity Rate
Local Pickup
Local Delivery

Verify Money exactness.

==================================================
83. PROMOTION FREE SHIPPING TESTS
==================================================

Test:

- no benefit → normal rate
- FreeShipping benefit → eligible rate becomes zero
- original amount retained in breakdown
- forbidden method remains forbidden
- benefit scoped appropriately
- multi-package / multi-fulfillment behavior

==================================================
84. WEIGHT / DIMENSION TESTS
==================================================

Test:

- decimal precision
- unit conversion
- kg/g
- cm/mm
- threshold boundaries
- no float drift
- invalid units rejected

==================================================
85. PACKAGE TESTS
==================================================

Test:

- one package
- max-weight split
- incompatible class split
- package type constraints
- deterministic packing result

==================================================
86. CARRIER TESTS
==================================================

Test provider registry:

- valid provider
- unknown provider rejection
- static/manual provider
- provider timeout simulation
- one provider fails while another succeeds
- credential isolation
- service mapping

==================================================
87. FULFILLMENT TESTS
==================================================

Test:

Single source enough stock
Multi-source split
Prefer no split where one eligible source can fulfill all
Source priority
Store/Market/Channel restrictions
stale external source exclusion via Inventory contracts
non-physical item handling
backordered/preorder readiness
source-method compatibility

==================================================
88. PICKUP TESTS
==================================================

Test:

- pickup offered with eligible stock
- pickup not offered without eligible source
- wrong Store context rejected
- inactive pickup source hidden
- pickup fee exact Money handling

==================================================
89. CROSS-TENANT SECURITY TESTS
==================================================

Explicitly test IDOR/mismatch attempts across:

- zones
- methods
- rate rules
- carrier configs
- credentials
- fulfillment source mappings
- package types

==================================================
90. RBAC TESTS
==================================================

Test:

- management API denial
- Livewire denial
- dedicated credential permission
- preview permission
- super admin behavior only where architecture permits

==================================================
91. API TESTS
==================================================

Test:

- Sanctum auth
- Context resolution
- Tenant isolation
- typed validation
- quote output
- no secret leakage
- deterministic output
- provider failure behavior

==================================================
92. ARCHITECTURE TESTS
==================================================

Verify:

- Inventory has no Shipping dependency
- Catalog has no Shipping dependency
- Pricing does not own shipping logic
- Shipping does not create Orders/Checkout
- Fulfillment does not own stock balances
- Carrier provider logic behind contracts
- Shipping method types behind registry
- no binary float
- no real label creation during quote
- no provider secret serialization
- no customer address persistence unless explicitly approved

==================================================
93. DATABASE DESIGN
==================================================

Potential entities may include:

shipping_zones
shipping_zone_rules
shipping_zone_assignments
shipping_methods
shipping_method_zone_assignments
shipping_rate_rules
shipping_classes
package_types
carriers
carrier_services
carrier_credentials
shipping_restrictions
pickup_locations / source mappings
shipping_source_method_mappings

fulfillment configuration tables if persistence is required

Exact schema must follow domain analysis.

Do NOT blindly create all tables if some concepts are registry/config-only.

==================================================
94. DATABASE CONSTRAINTS
==================================================

Use:

- tenant_id
- composite indexes
- unique tenant-scoped codes
- FK integrity
- check constraints
- explicit status rules
- no float money/weight
- encrypted credential storage

Add indexes for rate matching and zone lookup.

==================================================
95. POSTAL CODE STORAGE
==================================================

Postal codes are strings.

Never integer.

Preserve:

- leading zeroes
- letters
- spaces where country format requires them

Normalize for comparison but preserve display form if needed.

==================================================
96. RATE RULE STRUCTURE
==================================================

Rate rule parameters must be typed.

Do NOT implement unsafe general-purpose expression evaluation.

Use registry-backed conditions/actions.

Example:

conditions:
- zone
- min_weight
- max_weight
- min_subtotal
- max_subtotal
- min_quantity
- max_quantity
- shipping_class

action:
- fixed amount
- per unit
- per weight step
- per package
- percentage handling where allowed

==================================================
97. RULE PRECEDENCE
==================================================

Define:

- priority
- first-match vs aggregate behavior
- exclusive rules
- fallback rate

Do not leave ambiguous.

Document ADR.

==================================================
98. SHIPPING RATE BREAKDOWN
==================================================

Provide clear calculation breakdown.

Example:

base_rate
+ per_item
+ handling_fee
+ carrier_markup
- promotion_shipping_discount
= final_rate

All Money exact.

Useful for audit/debug/UI.

==================================================
99. CARRIER RATE NORMALIZATION
==================================================

Provider-specific response must be normalized before reaching domain/UI.

Do not allow DHL/UPS-specific response structures to leak across Shipping core.

==================================================
100. PROVIDER PLUGIN EXTENSION
==================================================

Design Provider SDK boundary compatible with future Plugin SDK.

Future plugin should be able to register:

- Carrier
- Services
- rate provider
- label provider
- tracking provider
- webhook handler

without modifying Shipping core.

Do NOT implement Plugin marketplace in this phase.

==================================================
101. SHIPPING METHOD PLUGIN EXTENSION
==================================================

Plugins may register custom shipping method/rate calculators.

Ensure registry namespacing/collision prevention.

==================================================
102. LOCALIZED CONTENT
==================================================

Shipping Method names/descriptions
Zone labels where displayed
Pickup instructions
Carrier service display names

must support project localization architecture.

Do not hardcode ar/en.

RTL/LTR throughout UI.

==================================================
103. SEO
==================================================

No significant SEO domain work required.

Do not add storefront SEO pages just for Shipping.

==================================================
104. PERFORMANCE
==================================================

Rate quote will be high-traffic later.

Avoid:

- N+1 zone rules
- loading all world zones
- loading all rate rules for unrelated stores
- repeated source queries
- unbounded provider calls

Use indexed filtering and batch loading.

==================================================
105. PROVIDER PARALLELISM
==================================================

Do not require parallel HTTP implementation unless needed.

But provider abstraction should not prevent future parallel carrier rate lookup.

Document extension.

==================================================
106. FAILURE SEMANTICS
==================================================

Rate quote engine should distinguish:

NO_METHOD_AVAILABLE

vs

PROVIDER_FAILURE

vs

DESTINATION_RESTRICTED

vs

UNFULFILLABLE_ITEMS

Use typed/structured errors.

==================================================
107. NO SILENT FALLBACK SHIPPING
==================================================

Do not silently select a default rate if no method is eligible.

If no rate is available, return structured no-method result.

Future Checkout can decide user-facing behavior.

==================================================
108. SHIPPING QUOTE PURITY
==================================================

Quote calculation must not:

- reserve stock automatically
- create Order
- create Shipment unless explicit preview object is non-persistent
- purchase label
- charge payment

Fulfillment planning may inspect availability but should not mutate reservation state unless an
explicit PHASE-06 design requires a neutral temporary reservation, which is NOT preferred.

Default: no reservation side effects.

==================================================
109. ADRS REQUIRED
==================================================

Create ADRs beginning from the next available ADR number after PHASE-05.

At minimum cover:

1. Shipping vs Fulfillment domain ownership
2. Shipping Zone matching and specificity precedence
3. Shipping Method registry/rate calculator architecture
4. Money and currency handling for shipping rates
5. Weight/dimension precision and units
6. Package/Packing strategy architecture
7. Carrier/CarrierService/provider abstraction
8. Provider credential security model
9. Fulfillment Plan and multi-source split strategy
10. InventorySource integration boundary
11. Shipping rate pipeline and deterministic ordering
12. Table-rate rule architecture
13. Promotion FreeShipping integration
14. Local Pickup / Local Delivery model
15. Shipment persistence vs ShipmentPlan decision
16. Provider failure / timeout semantics
17. Plugin extension boundary for carriers/methods

If ADR numbering has advanced, use next available IDs.

==================================================
110. STRICTLY OUT OF SCOPE
==================================================

DO NOT implement:

- Cart persistence
- Checkout
- Orders
- Customer Order history
- Payment gateway execution
- Financial Ledger entries
- Vendor commissions/payouts
- Supplier Purchase Orders
- Dropshipping supplier order execution
- automatic product purchasing
- real 3PL fulfillment execution
- returns/RMA
- refunds
- disputes
- subscription billing
- top-up execution
- license key delivery
- POS sales
- customer mobile apps
- Vendor app
- full customer storefront checkout
- AI agents
- MCP execution features

Do not begin PHASE-07.

==================================================
111. QUALITY GATES
==================================================

Run full project suite:

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

Rollback ONLY PHASE-06 migrations.

Then clean re-migration.

Run any dedicated Shipping/Fulfillment integration harnesses required.

==================================================
112. FINAL COMPLIANCE REVIEW
==================================================

Before committing, perform a source-level self-audit.

Search explicitly for:

- tenant fallback patterns
- unscoped findOrFail
- binary float
- arbitrary shipping method types
- arbitrary rate rule execution
- plaintext provider credentials
- provider secrets in API output
- customer address persistence
- Orders/Checkout models
- Inventory mutations during quote
- hidden Pricing duplication
- direct carrier-specific code outside provider adapters

Fix violations before final report.

==================================================
113. FINAL REPORT
==================================================

Return:

PHASE-06 COMPLETION REPORT

including:

1. Phase status.
2. Git commit hash.
3. Module architecture.
4. Shipping/Fulfillment dependency graph.
5. Shipping Zone model and matching precedence.
6. Shipping Method registry.
7. Rate calculation pipeline.
8. Money/currency handling.
9. Weight/dimension representation.
10. Package/Packing architecture.
11. First-party rate calculators implemented.
12. FreeShipping Promotion integration.
13. Carrier/provider architecture.
14. Carrier credential security.
15. Local Pickup.
16. Local Delivery.
17. Fulfillment Plan architecture.
18. Multi-source/split fulfillment behavior.
19. InventorySource integration.
20. Shipping source-method compatibility.
21. Shipment/ShipmentPlan decision.
22. Tracking/label extension contracts.
23. API endpoints.
24. Control Center screens.
25. Permissions/RBAC.
26. Audit coverage.
27. Tenant isolation.
28. Events.
29. Database tables/indexes/constraints.
30. ADRs.
31. PHASE-06-specific test count.
32. Total project test count.
33. Quality/security gate results.
34. Provider failure/timeout test results.
35. Remaining risks/limitations.
36. Explicit confirmation that no PHASE-07 functionality was implemented.

Then STOP.

DO NOT begin PHASE-07.