# PROJECT_MASTER_PLAN.md

> **Project:** Hyper Commerce Platform  
> **Authority:** Master architecture + AI coding-agent governance contract  
> **Status:** APPROVED BASELINE  
> **Current allowed work:** Governance/Skills Bootstrap only — no commerce feature implementation until a phase file is approved.  
> **Primary coding agents:** Google Antigravity CLI and/or Claude Code  
> **Baseline date:** 2026-08-31

---

# 0. MASTER RULE

`PROJECT_MASTER_PLAN.md` is the highest-authority technical document in this repository.

Every human developer, Antigravity agent, Claude Code agent, sub-agent, MCP agent, autonomous AI agent, CI job, or other coding automation MUST read and respect it before changing architecture or implementing a phase.

The agent MUST NOT modify this file unless the owner explicitly instructs it to do so.

If an architectural change appears necessary, create a proposal in `docs/proposals/` explaining the current rule, proposed change, reason, benefits, risks, migration impact, security impact, compatibility impact, and alternatives. Do not implement the architectural change until the owner approves it.

---

# 1. PHASE-GATED DEVELOPMENT

The project is implemented **phase by phase**.

The coding agent may work only on the active phase file, e.g. `docs/phases/PHASE-01-FOUNDATION.md`.

Before a phase: read this Master Plan, relevant accepted ADRs, the active phase file, load relevant project Skills, inspect repository state, produce a short execution plan, and verify the requested work belongs to the active phase.

After completing a phase: run all required tests/checks, update documentation, produce a completion report, and STOP. Never automatically begin the next phase. No phase file = no feature implementation.

---

# 2. PRODUCT VISION

This is a commercial **Hyper Commerce Platform**, not a single e-commerce website. It must be capable of becoming:

- Single Seller store
- Multi-Vendor Marketplace
- Hybrid Marketplace: platform + external sellers
- B2C and B2B
- physical and digital commerce
- subscriptions
- Top-Up / recharge
- licenses / serial keys
- services and bookings
- rentals
- auctions
- wholesale
- RFQ / quotations / negotiations
- Dropshipping
- Print-on-Demand
- POS retail
- multi-store platform
- multi-channel commerce
- multilingual / multicurrency / multi-market platform
- Self-Hosted licensed software
- SaaS platform
- Plugin Marketplace
- Theme Marketplace
- AI Agent Marketplace

The first deployment may be used personally for validation and marketing, but architecture must remain suitable for commercial licensing.

---

# 3. NON-NEGOTIABLE ARCHITECTURE

## 3.1 Modular Monolith first
Start as a **Modular Monolith**. Do not introduce microservices at the beginning. Modules must still have clean contracts so selected modules can later be extracted if scale requires it.

## 3.2 API-ready from day one
Business logic must be reusable by Blade/Livewire storefront, Control Center, REST API, Customer mobile app, Vendor mobile app, POS, Webhooks, MCP tools, internal AI Agents, and external integrations.

## 3.3 Extensible by design
The following must be extensible without rewriting Core: Product Types, Payment Gateways, Shipping Providers, Tax Providers, Search Engines, AI Providers, AI Agents, MCP Tools, Notification Providers, Sales Channels, Fulfillment Providers, Supplier Connectors, Page Builder Blocks, Themes, and Plugins.

Avoid scattered provider/type-specific `if` statements.

---

# 4. TERMINOLOGY

Use these terms consistently:

```text
Platform     = complete Hyper Commerce software ecosystem
Super Admin  = platform owner/operator above tenants
Tenant       = licensed/SaaS customer organization
Store        = commerce property/brand owned by a Tenant
Channel      = Website, Mobile, POS, B2B Portal, API, etc.
Vendor       = seller inside a marketplace Store
Vendor Storefront = public page/domain for a Vendor
User         = one identity that may buy, sell, work for a Vendor,
               operate a Tenant, and participate as Affiliate
```

Do not create separate authentication accounts just because one person can both buy and sell.

---

# 5. OPERATING MODES

Every installation must be configurable as:

```text
Single Seller
Multi Vendor
Hybrid
```

Hybrid means the platform itself can sell while Vendors also sell. Features should normally be disabled through configuration/feature flags rather than removed from the codebase.

---

# 6. APPROVED STACK

Use stable compatible patch versions within these approved major versions. Before installation, verify current stable compatibility from official docs and record the chosen versions in `docs/DEPENDENCIES.md`.

## Backend
```text
PHP 8.4+
Laravel 13
PostgreSQL
Redis
```

## Frontend
```text
Blade
Livewire 4
Tailwind CSS 4
daisyUI 5
Vite
Alpine.js through Livewire where applicable
```

The main storefront is server-rendered for SEO, speed, and operational simplicity. Do not add React/Vue/Next as the primary storefront without an approved architecture change. Future headless clients may consume the API.

## Search / Storage
```text
Laravel Scout abstraction
Meilisearch as initial external search engine
S3-compatible object storage
MinIO allowed for local development
```

## Preferred Laravel foundation capabilities
Install when the active phase requires them:

```text
laravel/sanctum
laravel/reverb
laravel/horizon
laravel/pulse
laravel/pennant
laravel/scout
laravel/socialite
laravel/ai
laravel/mcp
```

## Preferred supporting packages
```text
spatie/laravel-permission
spatie/laravel-activitylog
spatie/laravel-medialibrary
spatie/laravel-backup
spatie/laravel-health
spatie/laravel-data
spatie/laravel-query-builder
brick/money
```

## Quality tooling
```text
Pest
Laravel Pint
Larastan / PHPStan
Composer audit
npm audit
Browser E2E tooling when required
Telescope in development only when useful
```

Every dependency must be documented with package, version, module, reason, license, runtime/dev classification, and replacement strategy if architecturally important.

---

# 7. TARGET REPOSITORY STRUCTURE

```text
/
├── app/
│   ├── Core/
│   ├── Ai/
│   │   ├── Agents/
│   │   ├── Tools/
│   │   ├── Orchestrators/
│   │   └── Policies/
│   └── Support/
├── modules/
│   ├── Catalog/
│   ├── ProductTypes/
│   ├── Pricing/
│   ├── Inventory/
│   ├── Warehouses/
│   ├── Cart/
│   ├── Checkout/
│   ├── Orders/
│   ├── Marketplace/
│   ├── Vendors/
│   ├── Ledger/
│   ├── Wallet/
│   ├── Payouts/
│   ├── Fulfillment/
│   ├── Dropshipping/
│   ├── PrintOnDemand/
│   ├── Payments/
│   ├── Shipping/
│   ├── Taxes/
│   ├── Customers/
│   ├── Reviews/
│   ├── Messaging/
│   ├── Support/
│   ├── Affiliate/
│   ├── Referral/
│   ├── Loyalty/
│   ├── Promotions/
│   ├── Search/
│   ├── Seo/
│   ├── Cms/
│   ├── B2B/
│   ├── Auctions/
│   ├── Booking/
│   ├── Subscriptions/
│   ├── DigitalDelivery/
│   ├── GiftCards/
│   ├── Pos/
│   ├── Notifications/
│   ├── Analytics/
│   ├── Localization/
│   ├── Markets/
│   └── Licensing/
├── plugins/
├── themes/default/
├── docs/
│   ├── architecture/
│   ├── decisions/
│   ├── modules/
│   ├── phases/
│   ├── api/
│   ├── plugins/
│   ├── themes/
│   ├── ai/
│   ├── security/
│   ├── operations/
│   └── proposals/
├── tests/
├── scripts/
├── .agents/skills/
└── .claude/skills/
```

Exact autoload mechanics can be finalized in Foundation without changing these conceptual boundaries.

---

# 8. CONTEXT HIERARCHY AND DATA ISOLATION

```text
Platform
└── Tenant
    └── Store
        ├── Channel
        ├── Market
        └── Vendor(s)
```

Important runtime contexts may include `TenantContext`, `StoreContext`, `ChannelContext`, `VendorContext`, `MarketContext`, `LocaleContext`, `CurrencyContext`, and `UserContext`.

Never rely on accidental global scopes alone for security. Tenant isolation tests are mandatory.

The physical SaaS tenancy storage strategy — shared DB, schema-per-tenant, database-per-tenant, or hybrid — remains an explicit future ADR. Do not choose it casually before that phase.

---

# 9. MULTI-STORE CATALOG

A canonical Product should be reusable across multiple Stores rather than duplicated only because it is sold in multiple Stores.

```text
Product: Samsung TV
├── Electronics Store -> enabled, CHF 1299
└── Outlet Store      -> enabled, CHF 999
```

Store-level listing configuration may override visibility, price, merchandising, markets/channels, and approved presentation settings. Inventory may be shared or allocated by warehouse/store policy.

---

# 10. PRODUCT MODEL

Product Types must be extensible. Architecture must support at least:

```text
Physical
Digital Download
License / Key
Subscription
Top-Up
Gift Card
Service
Booking
Rental
Bundle
Variable
Configurable
Custom / Personalized
Affiliate / External
Preorder
Membership
Ticket / Event
Auction
Quote / RFQ
Wholesale
Made-to-Order
Print-on-Demand
```

New first-party modules or plugins must be able to register Product Types. Product-type-specific behavior can register validation, fields, checkout, pricing, fulfillment, stock behavior, storefront templates, and order-line metadata.

Do not scatter `if product_type == ...` across unrelated modules.

Support configurable Attributes, Attribute Sets, Options, Variants, Specifications, Facets, Custom Fields, customer-entered fields, localized labels, and category-specific attribute models. This powers search filtering and comparison.

---

# 11. MARKETPLACE / VENDORS

Support Vendor onboarding, approval/verification, KYC integration points, Vendor plans, staff/RBAC, commissions, wallet/payables, payouts, Vendor reviews, policies, analytics, suppliers, dropshipping, API access, and AI capabilities by plan.

Default business policy:

```text
Free Vendor Plan -> manual admin approval
Paid Monthly Plan -> automatic approval
```

This remains configurable.

Vendor plan entitlements may control product limits, staff, custom domain, own shipping rules, promotions, private suppliers, dropshipping, API, analytics, AI, and commission rules.

Every Vendor may have a storefront containing name, logo, cover, description, rating, verification, followers, products, categories/collections, deals, policies, shipping/return information, reviews, business/contact information, and Follow Vendor.

Supported URL modes:

```text
https://platform.example/{vendor-slug}
https://{vendor-slug}.platform.example
https://vendor-domain.example
```

Maintain reserved slugs such as `admin`, `api`, `login`, `register`, `cart`, `checkout`, `account`, `orders`, `products`, `categories`, `search`, `support`, `plugins`, `themes`, `system`, `vendor`, `seller`.

Custom-domain changes must preserve canonical URL, redirect, and sitemap SEO behavior.

---

# 12. CONTROL CENTER / SUPER ADMIN

Use a unified professional Control Center shell where practical. Admin and Vendor views are governed by:

```text
Identity + Role + Permission + Context + Plan + Feature Flag
```

Do not duplicate whole applications unnecessarily. Authorized context switching/impersonation must be audited.

Super Admin is separate from Tenant Admin and manages tenants, licenses, SaaS plans, releases, official extension marketplaces, global platform settings, and platform health.

---

# 13. ORDERS / FULFILLMENT / DROPSHIPPING

A checkout may include platform products and multiple Vendors.

Model separately:

```text
Master Order
├── Seller/Vendor Order
└── Seller/Vendor Order
    └── Fulfillment(s)
        └── Shipment(s)
```

Do not conflate Order, Seller Order, Fulfillment, Shipment, Payment, and Ledger Transaction.

Fulfillment types include Own Stock, Vendor Stock, Dropshipping, 3PL, Print-on-Demand, Made-to-Order, Digital, Service, and Hybrid.

Dropshipping is a **first-party strategic module**, not merely a third-party plugin:

```text
Fulfillment Core
└── First-party Dropshipping Module
    └── Supplier Connector Plugins
```

Support global/platform suppliers, private Vendor suppliers, tenant suppliers, supplier accounts/warehouses/products/variants/SKUs, mappings, stock/cost/price sync, content/media sync, import/markup rules, automatic/manual supplier ordering, purchase orders, tracking, supplier invoices, returns/RMA, lead time, supplier rating, currencies, and margin analytics.

One SKU may have multiple suppliers. Routing may use destination, stock, cost, delivery time, shipping cost, priority, rating, margin, and SLA. Support fallback suppliers.

Supplier-specific services/APIs are plugins. Print-on-Demand is first-party fulfillment with provider connectors as plugins.

---

# 14. INVENTORY / WAREHOUSES

Support multi-warehouse, Vendor warehouse, supplier warehouse, variant stock, reservations, transfers, stock movements, backorders, preorders, low-stock alerts, SKU, barcode, and fulfillment routing.

Prevent overselling with correct PostgreSQL transaction/locking strategies and Redis coordination only where justified.

---

# 15. MONEY / PAYMENTS / LEDGER

**Never use binary floating point for money.** Use minor units and/or `brick/money`. Every monetary value has currency context.

Payment providers are extensible and may support payment, authorization/capture, full/partial refund, recurring capability, tokenization, 3DS, marketplace split, and payouts.

Support both platform collection + Vendor payout and gateway-native split payments.

Build an internal auditable ledger for customer payments, platform revenue, Vendor payable/payout, Affiliate payable, taxes, refunds, chargebacks, wallet, Store Credit, Gift Cards where appropriate, supplier costs, and adjustments.

Balances derive from ledger movements, not arbitrary editable balance fields. Financial operations require idempotency.

Vendor commission rules may vary by platform default, Vendor, plan, category, Product, Product Type, Market, payment method, and campaign.

---

# 16. AFFILIATE / MARKETING / B2B

Affiliate is first-party: Affiliate profiles, referral links/codes, clicks, conversions, campaigns, attribution windows, first-click/last-click/coupon/manual strategies, commissions, payables/wallet, payouts, fraud signals, and reports.

Affiliate may promote platform, Store, Vendor, category, Product, or campaign.

Support promotions, coupons, flash sales, referrals, loyalty, abandoned cart, recommendations, cross-sell, upsell, and frequently-bought-together.

B2B supports company accounts, company staff/roles, VAT/tax IDs, purchase orders, RFQ, quotes, negotiations, negotiated pricing, credit limits, payment terms, wholesale pricing, and MOQ.

Auctions support bids, increments, start/end, reserve rules, bid history, concurrency safety, winner, settlement, and notifications.

Bookings/services support calendars, availability, slots, resources/employees, locations, capacity, pricing, cancellation, reminders, and service fulfillment.

---

# 17. DIGITAL / SHIPPING / RETURNS

Support subscriptions, renewals, grace periods, failed-payment recovery, upgrades/downgrades where supported, digital downloads, signed download access, download limits, license keys, memberships, Gift Cards, and Top-Up fulfillment.

Shipping uses provider/method contracts and supports zones, weight/price/location rules, flat/free shipping, Vendor shipping, pickup, carrier plugins, tracking, and split shipments.

Returns support item-level return, RMA, partial/full refund, Vendor dispute, evidence, admin intervention, return shipping, supplier return, and ledger effects.

---

# 18. CUSTOMER / CHAT / CMS / SEO / SEARCH

Architecture supports Wishlist, Compare, Recently Viewed, Follow Vendor/Product, Price Drop Alert, Back-in-stock Alert, Save for Later, Gift Registry, Product Q&A, Product/Vendor Reviews, verified purchase, review media/replies/moderation, loyalty, rewards, wallet, Store Credit, Gift Cards, Preorder, and waiting lists.

Buyer ↔ Seller messaging is first-party and realtime using Laravel Reverb or approved compatible infrastructure. Persistent application/database data is authoritative; realtime transport is not. Include authorization, attachments where allowed, read state, notifications, moderation, context, and abuse controls.

CMS supports Pages, Blog, FAQ, Menus, Banners, Media, Redirects, and localized content.

Page Builder supports reusable Theme-compatible blocks and plugin-provided blocks.

SEO is first-party: friendly URLs, slugs, title/description, canonical, redirects, Open Graph, structured data, product schema, sitemap, robots, hreflang, multilingual SEO, and Vendor-domain SEO.

Search uses abstraction. Initial external engine: Meilisearch through Laravel Scout where appropriate. Support full text, autocomplete, typo tolerance, synonyms, facets, filters, multilingual search, trending/no-result analytics, merchandising, and future AI natural-language search.

---

# 19. INTERNATIONALIZATION / MARKETS / RTL

Languages are installable/configurable from Control Center. Metadata includes locale, name, native name, RTL/LTR, enabled, and fallback.

Separate Core/module/plugin/theme translations from localized product/category/attribute/CMS/notification content.

Initial Arabic/English must not become a two-language limitation.

All reusable UI must support RTL/LTR. Prefer logical Tailwind/CSS utilities such as `ms`, `me`, `ps`, `pe`, `start`, and `end`. Avoid hardcoded left/right when logical direction is intended.

Markets define countries, languages, currencies, domain, catalog visibility, pricing, payments, shipping, taxes, and promotions.

Automatic locale/region/currency may use user preference, stored preference, Store/Market domain, IP geolocation, browser language, and configured policy. Never rely only on IP. User override must be supported where allowed.

Store canonical timestamps, normally UTC, then render with explicit timezone context.

---

# 20. CHANNELS / POS / MOBILE / API

Sales Channels include Website, Customer App, Vendor App, POS, Marketplace, B2B, and API. Products can differ in availability/configuration by Channel.

POS is first-party and must reuse Catalog, Pricing, Inventory, Customers, Orders, Payments, Returns, and RBAC instead of duplicating logic.

Mobile supports separate Customer and Vendor apps over the same platform services/API.

Preferred first-party API auth: Laravel Sanctum. Third-party OAuth ecosystem may add Passport/equivalent only when required.

API must be versionable with authorization, rate limiting, pagination, consistent errors, idempotency, documentation, and deprecation strategy.

Webhooks require signing, retries, logs, idempotency, versioning, and secret rotation.

---

# 21. PLUGIN SYSTEM

Build a first-party Plugin SDK.

Plugins may register routes, services, events, migrations, settings, permissions, Control Center navigation, components/views, API endpoints, webhooks, schedules/jobs, Product Types, Payment/Shipping/Tax Providers, Supplier Connectors, Page Builder blocks, analytics, AI Agents, MCP Tools, translations, and Theme extension hooks.

Every plugin has a manifest containing ID, name, version, author, license, platform compatibility, dependencies, requested permissions, provided capabilities, migrations, and update metadata.

Plugins MUST NOT edit Core source. They integrate through contracts, events, registries, providers, hooks, and documented APIs.

Plan for secure ZIP installation, integrity/signing, compatibility checks, error isolation, and Plugin Marketplace.

---

# 22. THEME SYSTEM

Storefront is Theme-driven. Initial built-in Theme: `themes/default`.

Default Theme rule: **simple, functional, professional, responsive; use Tailwind/daisyUI defaults and reusable platform UI components. No unnecessary custom design.**

Prefer reusable abstractions such as:

```text
<x-ui.button>
<x-ui.modal>
<x-ui.table>
<x-ui.input>
<x-ui.select>
<x-ui.tabs>
<x-ui.card>
```

Do not spread raw daisyUI implementation details throughout business code.

Theme architecture supports manifest, layouts, pages, components, sections, assets, translations, Product Type templates, settings, hooks, and Child Themes.

Plan for Theme Marketplace with Free/Premium, official/third-party, install/update/license/reviews/compatibility.

---

# 23. AI / MCP PLATFORM

AI is a platform subsystem.

Target Agents include:

```text
AI Orchestrator
├── Development Agent
├── Maintenance/SRE Agent
├── Security Agent
├── QA Agent
├── Database Agent
├── Performance Agent
├── SEO Agent
├── Content Agent
├── Customer Support Agent
├── Vendor Support Agent
├── Marketing Agent
├── Merchandising Agent
├── Pricing Agent
├── Fraud Agent
├── Localization Agent
├── Accessibility Agent
├── Release Agent
└── Backup/Recovery Agent
```

Prefer Laravel AI SDK as Laravel integration layer. AI Provider remains configurable.

Prefer Laravel MCP for first-party MCP capabilities. Logical tool groups may include Commerce, Developer, System, SEO, Content, Support, Vendor, and Security MCP. MCP Tools call authorized application/domain services and never bypass domain rules.

---

# 24. FULL AUTONOMOUS AI CONTROL

Support modes:

```text
Disabled
Read Only
Diagnose
Execute With Approval
Full Autonomous Control
```

**Full Autonomous Control is OFF by default.**

When explicitly enabled and permissioned, AI may be allowed to read/create/modify/delete files, use Git, execute tools/commands, install/remove dependencies, run migrations, operate approved database tools, create/modify Plugins/Themes, run tests, deploy, rollback, manage queues/cache/scheduler, call APIs, and modify source/Core if that specific permission is enabled.

Commercial customers receive a strong warning before activation.

## External Kill Switch
Provide server/CLI control outside normal UI. Concept:

```text
AI_AUTONOMY_ENABLED=false
php artisan ai:disable
php artisan ai:kill
```

Exact names are phase-specific; the external control requirement is mandatory.

Privileged AI actions must be audited with Agent identity, authority, tool, affected resources, result, timestamp, approval mode, correlation ID, and rollback/release reference where relevant.

Preferred autonomous change flow:

```text
Detect -> Diagnose -> Plan -> Branch/change set -> Modify
-> Static analysis -> Tests -> Security checks -> Staging
-> Health checks -> Release -> Observe -> Keep or Rollback
```

Distinguish Official Development AI working on the official product repository from Customer Instance AI operating a deployment. Customer Core modification can be enabled but must warn about update conflicts; prefer settings, Plugins, and Themes for customization.

Plan for an AI Agent SDK/Marketplace where approved extensions can register Agents, tools, MCP tools, prompts, knowledge sources, triggers, schedules, policies, and UI integrations.

No AI Agent may autonomously rewrite this Master Plan.

---

# 25. LICENSING / SAAS / FEATURE FLAGS

Support both Self-Hosted and SaaS. Keep licensing separate from commerce logic. Use centralized Entitlements/Capabilities rather than scattered license checks.

Use Laravel Pennant where appropriate and distinguish Platform Feature, License Entitlement, Tenant Feature, Store Feature, Vendor Plan Feature, and Experimental Rollout.

Example keys:

```text
marketplace.enabled
dropshipping.enabled
auction.enabled
pos.enabled
ai.enabled
ai.full-control
vendor.custom-domain
vendor.private-suppliers
booking.enabled
```

---

# 26. SECURITY / OPERATIONS / DATA RULES

Security is part of Definition of Done. Require explicit authorization, CSRF protection, validation, escaping, secret handling, rate limiting, brute-force controls, privileged-user 2FA capability, secure uploads, SSRF-aware integrations, signed webhooks, financial idempotency, dependency audits, Plugin permissions, AI Tool permissions, tenant isolation, audit logs, backups/restore, and appropriate security headers/CSP.

Observability eventually includes application logs, Activity Log, Pulse, Horizon, failed jobs/webhooks/payments, queue/scheduler health, DB/storage/cache/realtime health.

Backups require documented restore procedures and periodic restore verification.

Database/code rules:

1. PostgreSQL is relational source of truth.
2. Redis is not durable business truth.
3. Search index is not source of truth.
4. Migrations belong to owning modules.
5. Use indexes deliberately.
6. Use JSONB for truly dynamic metadata only.
7. Do not use EAV/JSON to avoid proper modeling.
8. Financial/audit records require stronger immutability.
9. Migrations must be safe for existing installations.
10. Destructive changes require strategy.
11. Controllers stay thin.
12. Livewire is not the domain layer.
13. Avoid giant Models containing the whole business domain.
14. Use actions/services/value objects/contracts/policies where appropriate.
15. Use DB transactions for atomic commerce invariants.
16. Do not hold slow external API calls inside long DB transactions.
17. Dangerous duplicate-sensitive operations require idempotency.
18. No float for money.
19. No hardcoded locales/currencies/providers/Product Types.
20. No cross-tenant unscoped data access.

---

# 27. TESTING STANDARD

A feature is incomplete without appropriate automated tests.

Use Unit, Feature, Integration, Architecture, API, Browser/E2E where required, and security regression where required.

Mandatory high-risk coverage includes tenant isolation, Store isolation, Vendor RBAC, Money arithmetic, commissions, ledger balance, Affiliate attribution, inventory reservations, oversell prevention, checkout, payment idempotency, refunds, payouts, order/fulfillment splitting, supplier fallback, Plugin isolation, Theme fallback, RTL/LTR, AI permissions, MCP authorization, AI kill switch, license entitlements, and API authorization.

Never weaken tests simply to make implementation pass.

---

# 28. DOCUMENTATION / ADR

Required docs include:

```text
docs/architecture/
docs/decisions/
docs/modules/
docs/phases/
docs/api/
docs/plugins/
docs/themes/
docs/ai/
docs/security/
docs/operations/
docs/proposals/
docs/DEPENDENCIES.md
```

Important decisions require ADRs, e.g. Modular Monolith, PostgreSQL, Money/No-Floats, Product Type contract, Plugin isolation, Theme system, Multi-Tenancy storage model, AI Full Control, Multi-Store Catalog, Ledger model.

Accepted ADRs cannot be silently contradicted.

---

# 29. MANDATORY PROJECT SKILLS

Before feature development, create project-local Agent Skills.

Antigravity:

```text
.agents/skills/<skill-name>/SKILL.md
```

Claude Code:

```text
.claude/skills/<skill-name>/SKILL.md
```

Create equivalent Skills for both environments:

```text
project-governance
architecture-boundaries
laravel-platform
postgresql-data-design
multi-tenancy
multi-store-context
commerce-domain
catalog-product-types
money-ledger
marketplace-vendors
fulfillment-dropshipping
affiliate-marketing
localization-markets-rtl
api-webhooks
realtime-messaging
plugin-sdk
theme-sdk
ai-agents-mcp
security-hardening
testing-quality
performance-observability
seo-commerce
devops-release
documentation-adr
```

Each `SKILL.md` must contain YAML frontmatter with `name` and clear `description`, focused instructions, checklist, forbidden shortcuts, and validation steps. Skills should reference this Master Plan instead of duplicating it.

Example:

```markdown
---
name: money-ledger
description: Enforces Hyper Commerce money, currency, ledger, commission, refund and payout architecture. Use for code touching prices, payments, balances, commissions, wallets, payouts or refunds.
---

# Money / Ledger

Before editing:
1. Read PROJECT_MASTER_PLAN.md.
2. Read relevant ADRs.
3. Read active phase.

Rules:
- Never use float for money.
- Respect ledger invariants.
```

Skill responsibilities:

```text
project-governance           phase boundaries and Master Plan authority
architecture-boundaries      Modular Monolith; Core/Module/Plugin separation
laravel-platform             Laravel conventions; thin controllers; Livewire boundaries
postgresql-data-design       schema; transactions; locks; indexes; safe migrations
multi-tenancy                TenantContext; isolation; authorization; tests
multi-store-context          Store/Channel/Market/Vendor contexts; shared Product listings
commerce-domain              Cart; Checkout; Orders; Fulfillment; Returns
catalog-product-types        Product Types; Attributes; Variants; extension contracts
money-ledger                 Money; ledger; commission; refunds; payouts; idempotency
marketplace-vendors          Vendors; plans; staff; RBAC; storefronts; approval
fulfillment-dropshipping     fulfillment; suppliers; dropshipping; fallback; POD
affiliate-marketing          attribution; Affiliate; referral; promotions; fraud
localization-markets-rtl     languages; Markets; currency; timezone; RTL/LTR
api-webhooks                 API; Sanctum; authz; idempotency; signing; retries
realtime-messaging           Reverb; chat; persistence; authz; moderation
plugin-sdk                   manifest; permissions; lifecycle; Core isolation
theme-sdk                    manifest; Child Themes; templates; Page Builder; UI layer
ai-agents-mcp                Laravel AI/MCP; Orchestrator; Full Control; Kill Switch; audit
security-hardening           secrets; authz; uploads; SSRF; tenants; AI/plugin safety
testing-quality              Pest; static analysis; architecture/high-risk tests
performance-observability    Pulse; Horizon; N+1; cache; queues; metrics; health
seo-commerce                 canonical; schema; hreflang; sitemap; multilingual/vendor SEO
devops-release               environments; Git; CI; staging; release; rollback; backups
documentation-adr            phase/module docs; ADR; proposal; dependency registry
```

Keep Antigravity and Claude Code Skills aligned. A Bootstrap phase may create a canonical source + sync script, but both discovery directories must contain valid usable Skills.

External Skills/plugins may be added only after source, instructions, scripts, license, and security impact are reviewed. Do not install random third-party Skills merely because they are popular.

---

# 30. RECOMMENDED CODING SUB-AGENTS

Where supported:

```text
architect
laravel-engineer
database-architect
commerce-engineer
marketplace-engineer
payments-ledger-engineer
frontend-engineer
api-engineer
realtime-engineer
plugin-architect
theme-architect
ai-mcp-engineer
security-reviewer
test-engineer
seo-engineer
devops-engineer
```

The architect protects boundaries but cannot override this Master Plan. No sub-agent may expand active-phase scope.

---

# 31. ANTIGRAVITY / CLAUDE CODE ENTRY RULES

## Antigravity
Project Skills live in `.agents/skills/`. Start from repository root, verify Skills are detected, use `project-governance`, read this file + active phase, and work only inside that phase. Verify current official configuration syntax before creating agent/sub-agent configuration; do not invent unsupported config.

## Claude Code
Project Skills live in `.claude/skills/`. Also create a concise `CLAUDE.md` stating:

```text
PROJECT_MASTER_PLAN.md is authoritative.
Load project-governance before engineering.
Read the active docs/phases/PHASE-*.md.
Never advance phases automatically.
Never change architectural invariants without approval.
Run required tests and documentation.
```

Do not duplicate this entire Master Plan into `CLAUDE.md`.

---

# 32. MANUAL SKILL BOOTSTRAP IF NEEDED

If the coding agent cannot create project Skills automatically:

```bash
mkdir -p .agents/skills
mkdir -p .claude/skills
```

Then create, for every Skill:

```bash
mkdir -p .agents/skills/project-governance
mkdir -p .claude/skills/project-governance
```

and create a `SKILL.md` inside each folder. Repeat for the section 29 list.

Recommended first agent prompt:

```text
Read PROJECT_MASTER_PLAN.md fully.

Perform BOOTSTRAP ONLY.

Create the mandatory project Skills listed in the Master Plan for both:
- .agents/skills/
- .claude/skills/

Create the minimum docs skeleton required by the Master Plan.
Create a concise CLAUDE.md for Claude Code.

Do not implement commerce features.
Do not choose unresolved architecture.
Do not begin Phase 1.
Do not modify PROJECT_MASTER_PLAN.md.

When finished report:
1. files created,
2. Skill manifest,
3. validation performed,
4. unsupported features or conflicts,
then STOP.
```

---

# 33. AGENT CHANGE CLASSIFICATION

For each task classify work as:

```text
A. Fits active phase + architecture -> implement.
B. Fits phase but detail is ambiguous -> choose conservative compatible solution and document.
C. Requires architecture change -> proposal; do not implement change.
D. Belongs to future phase -> do not implement.
E. Security-critical unresolved risk -> stop risky part and document decision needed.
```

Autonomy is not permission to violate governance.

---

# 34. ENVIRONMENTS / GIT / RELEASES

Use:

```text
Local
Development
Staging
Production
```

Autonomous development primarily works through Development/Staging before Production.

Use Git from the beginning:

```text
task -> branch -> implementation -> tests/static analysis
-> review/agent validation -> staging -> release -> production
```

Releases require migration awareness, changelog, compatibility consideration, and rollback path where relevant.

---

# 35. DEFINITION OF DONE

A phase is complete only when all applicable requirements are complete:

- implementation
- architecture compliance
- migrations
- tests
- static analysis
- formatting
- security checks
- tenant isolation checks
- RTL/LTR checks for UI
- audit behavior
- documentation
- dependency registry
- accepted ADR updates
- no hidden TODO replacing required functionality
- completion report

Then STOP.

---

# 36. SOURCE OF TRUTH ORDER

When instructions conflict:

```text
1. Explicit owner instruction for the active task
2. PROJECT_MASTER_PLAN.md
3. Accepted ADRs
4. Active phase specification
5. Module architecture docs
6. Project Skills
7. Existing implementation
8. External official documentation
9. Agent assumptions
```

Existing code does not override this plan merely because it already exists.

---

# 37. CURRENT PROJECT STATE

```text
Master vision: APPROVED
Master architecture baseline: APPROVED
Detailed implementation phases: NOT YET DEFINED
Allowed coding now: Governance/Skills Bootstrap only
Commerce feature coding: NOT AUTHORIZED YET
```

Next action: divide the project into explicit implementation phases and provide one phase to the coding agent at a time.

Each phase file should contain:

```text
Objective
Included Scope
Explicitly Excluded Scope
Required Skills
Prerequisites
Architecture/ADRs
Database Work
Backend Work
Frontend Work
API Work
Security
Tests
Documentation
Acceptance Criteria
Stop Condition
```

---

# 38. FINAL GOVERNANCE STATEMENT

The platform is intentionally ambitious.

Do not solve complexity by silently removing agreed capabilities.

Control complexity through Modular boundaries, contracts, phase gates, Agent Skills, ADRs, tests, security, observability, documentation, and reversible releases.

No AI coding agent is authorized to redefine the product vision, skip the phase process, rewrite the Master Plan, or redesign the architecture on its own.
