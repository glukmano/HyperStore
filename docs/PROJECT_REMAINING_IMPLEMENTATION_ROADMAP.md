# HyperStore — Remaining Implementation Roadmap

> **Status**: Informational, read-only audit. Does not modify `PROJECT_MASTER_PLAN.md` and carries no authority over it.
> **Baseline**: commit `9353ff61be70ad742006f882e2f98c46d6a7745a` (Phase-13 closed), Phase-14 in progress.
> **Important**: `PROJECT_MASTER_PLAN.md` defines numbered **topic** sections (0–38), not a sequential implementation-phase list. The `docs/phases/PHASE-NN-*.md` numbering below Phase-14 is **proposed by this document**, not defined by the Master Plan. Any phase number past Phase-14 in this file is a suggestion for the owner to accept, reject, or reorder — it becomes authoritative only once the owner approves an actual `docs/phases/PHASE-NN-*.md` file.

---

## Part 1 — Master topic coverage matrix

Every numbered Master Plan section, classified against actual repository state (13 modules exist: Cart, Catalog, Checkout, Dropshipping, Fulfillment, Inventory, Ledger, Marketplace, Order, Payment, Pricing, Promotions, Shipping; no `plugins/`, no `themes/`, no `app/Ai/`).

| § | Topic | Status | Evidence |
|---|---|---|---|
| 0–1 | Master rule, phase-gating | GOVERNANCE / NON-IMPLEMENTATION TOPIC | — |
| 2 | Product vision | GOVERNANCE / NON-IMPLEMENTATION TOPIC | — |
| 3 | Non-negotiable architecture | GOVERNANCE (substantially honored) | Modular monolith confirmed throughout Phases 01–13 |
| 4 | Terminology | GOVERNANCE | — |
| 5 | Operating modes (Single/Multi/Hybrid) | PARTIALLY COMPLETE | Marketplace module (Phase-11) supports multi-vendor; explicit mode-level feature-flag switch not confirmed |
| 6 | Approved stack | COMPLETE / GOVERNANCE | Laravel 13/PHP 8.4/PostgreSQL in active use |
| 7 | Target repository structure | PARTIALLY COMPLETE | 13 of ~40 listed `modules/` exist; `app/Core` exists |
| 8 | Context hierarchy / data isolation | COMPLETE | Tenant/Store/Vendor `ContextManager` confirmed (Phase-12 audit) |
| 9 | Multi-store catalog | PARTIALLY COMPLETE | Catalog (Phase-03/04) + Vendor listings (Phase-11) exist; full store-level override matrix not re-verified this turn |
| 10 | Product model / Product Types | PARTIALLY COMPLETE | `ProductTypeRegistry` confirmed real with ~21 registered types incl. Preorder, Physical, Digital, Rental, Subscription; not every listed type independently verified |
| 11 | Marketplace / Vendors | COMPLETE | Phase-11, ADR-0107–0113 (plans, commission engine, payable subledger, payouts, storefront resolver, domain verification) |
| 12 | Control Center / Super Admin | COMPLETE | Phase-12, ADR-0114–0117 (RBAC, impersonation audit, licensing/health) |
| 13 | Orders / Fulfillment / Dropshipping | COMPLETE | Phase-13, ADR-0118–0121, closed this session |
| 14 | Inventory / Warehouses | COMPLETE (Phase-05) + PHASE-14 CORE (in progress) | See Phase-14 plan |
| 15 | Money / Payments / Ledger | PARTIALLY COMPLETE | Payments (Phase-09) + movement-only Ledger (Phase-10, ADR-0101–0106) done; Wallet/Store Credit/Gift Card balances/chargebacks NOT built (Ledger only has `payment_clearing`/`customer_funds_liability` account roles) |
| 16 | Affiliate / Marketing / B2B | NOT STARTED (Promotions partially) | `modules/Promotions` exists; no Affiliate, Referral, Loyalty, B2B, Auctions, or Booking module |
| 17 | Digital / Shipping / Returns | PARTIALLY COMPLETE | Shipping (Phase-06) + Returns/RMA (Phase-13) done; Subscriptions, digital downloads, license keys, Top-Up, Gift Card fulfillment NOT started |
| 18 | Customer / Chat / CMS / SEO / Search | NOT STARTED | No Cms, Seo, Search, Messaging, Reviews, or Support module exists |
| 19 | Internationalization / Markets / RTL | NOT STARTED | No Localization/Markets module; multi-currency exists narrowly within Pricing (Phase-04) |
| 20 | Channels / POS / Mobile / API | PARTIALLY COMPLETE | `Channel` concept exists (referenced in Phase-13 tests); Sanctum-based API exists across modules; POS and dedicated mobile API NOT started |
| 21 | Plugin system | NOT STARTED | No `plugins/` directory, no Plugin SDK |
| 22 | Theme system | NOT STARTED | No `themes/` directory |
| 23 | AI / MCP platform | NOT STARTED | No `app/Ai/` directory |
| 24 | Full autonomous AI control | NOT STARTED | Depends on §23 |
| 25 | Licensing / SaaS / feature flags | NOT STARTED | No Licensing module; no confirmed Pennant usage |
| 26 | Security / operations / data rules | GOVERNANCE (ongoing, substantially honored) | Tenant isolation, idempotency, no-float-money confirmed repeatedly across Phases 05–13 |
| 27 | Testing standard | GOVERNANCE (ongoing, substantially honored) | Real multi-process Postgres concurrency tests established and reused since Phase-05 |
| 28 | Documentation / ADR | GOVERNANCE (ongoing, partial gap) | `docs/decisions/` current through ADR-0121; **`docs/phases/` has no file for Phases 10–13** — a pre-existing governance drift, not introduced by Phase-14 |
| 29 | Mandatory project skills | PARTIALLY COMPLETE | `project-governance` confirmed present; full 22-skill list not re-verified this session |
| 30–38 | Sub-agents, entry rules, bootstrap, classification, environments, DoD, source-of-truth, project state, final statement | GOVERNANCE / NON-IMPLEMENTATION TOPIC | — |

---

## Part 2 — Completed sequential phases (evidence: git log, ADR trail)

| Phase | Scope | Status |
|---|---|---|
| 01 | Platform Foundation | COMPLETE |
| 03 | Catalog, Product Types, Attributes & Variants | COMPLETE |
| 04 | Pricing, Multi-Currency, Taxes & Promotions | COMPLETE |
| 05 | Inventory, Warehouses & Multi-Source Stock | COMPLETE |
| 06 | Shipping, Fulfillment, Zones & Carriers | COMPLETE |
| 07 | Cart & Checkout Orchestration | COMPLETE |
| 08 | Orders & Order Management | COMPLETE |
| 09 | Payments & Payment Orchestration | COMPLETE |
| 10 | Ledger (movement-only, ADR-0101–0106) | COMPLETE (no phase file — pre-existing doc gap) |
| 11 | Marketplace / Vendor boundaries (ADR-0107–0113) | COMPLETE (no phase file) |
| 12 | Control Center / Super Admin / RBAC (ADR-0114–0117) | COMPLETE (no phase file) |
| 13 | Orders, Fulfillment, Dropshipping & RMA (ADR-0118–0121) | COMPLETE (no phase file), closed this session |
| **14** | **Inventory Operations Extension** | **IN PROGRESS (this phase)** |

Note: no `docs/phases/PHASE-02*.md` exists either — Phase-02 was apparently skipped or absorbed into Phase-01/03; not investigated further as it predates this audit's scope.

---

## Part 3 — Proposed remaining sequential phases (all PROPOSED, not Master-defined)

Every phase below is a **PROPOSED SEQUENTIAL PHASE** — grouped from the NOT STARTED / PARTIALLY COMPLETE rows in Part 1. Numbering, grouping, and order are this document's recommendation only.

### PROPOSED PHASE-15 — Storefront Theme System & Public Storefront
- **Master sections**: 22 (Theme System), relevant parts of §9/§10 (customer-facing catalog rendering)
- **Exists**: Blade/Livewire stack chosen (§6); no `themes/` directory, no theme manifest/layout system
- **Remains**: theme manifest, layouts/pages/components/sections, `themes/default`, reusable `<x-ui.*>` component library, Product Type storefront templates, Child Theme support
- **Dependencies**: Catalog (done), Pricing (done), Marketplace vendor storefronts (done)
- **Why separate**: distinct rendering-layer subsystem; nothing else structurally depends on it, but real customer-facing commerce is blocked without it — high business priority to sequence early

### PROPOSED PHASE-16 — Plugin SDK & Extensibility Platform
- **Master sections**: 21 (Plugin System)
- **Exists**: nothing — no `plugins/` directory or SDK
- **Remains**: manifest format, route/service/event/migration/permission registration, Product Type / Payment / Shipping / Tax provider extension points, secure ZIP install, Plugin Marketplace foundation
- **Dependencies**: Storefront (Phase-15, for theme/plugin interaction), stable Core contracts across existing modules
- **Why separate**: formalizing extension points is cleaner once Core commerce (Phases 01–14) is stable, rather than retrofitting a plugin boundary onto modules already in flux

### PROPOSED PHASE-17 — Customer Engagement, CMS, SEO & Search
- **Master sections**: 18 (Customer/Chat/CMS/SEO/Search)
- **Exists**: nothing — no Cms, Seo, Search, Messaging, Reviews module
- **Remains**: Wishlist, Compare, Recently Viewed, Follow Vendor/Product, Price/Back-in-stock alerts, Reviews + moderation, buyer↔seller messaging (Reverb), CMS (Pages/Blog/FAQ/Menus), SEO (canonical/schema/sitemap), Search (Meilisearch/Scout)
- **Dependencies**: Catalog, Marketplace, Storefront (Phase-15)
- **Why separate**: coherent customer-experience layer, naturally grouped, depends on Storefront existing to be meaningful

### PROPOSED PHASE-18 — Internationalization, Markets & RTL
- **Master sections**: 19
- **Exists**: narrow multi-currency support inside Pricing (Phase-04); no Localization/Markets module
- **Remains**: installable languages, Market entity (country/currency/domain/catalog visibility), RTL/LTR UI audit, locale/region detection policy
- **Dependencies**: Storefront (Phase-15) — RTL/LTR is a UI-layer concern best validated once real templates exist
- **Why separate**: cross-cutting concern touching many modules; best done as one focused pass rather than piecemeal

### PROPOSED PHASE-19 — Affiliate, Referral, Loyalty & Marketing
- **Master sections**: 16 (Affiliate/Marketing part), completing Promotions
- **Exists**: `modules/Promotions` (coupons/flash-sale groundwork, exact coverage not re-verified this turn)
- **Remains**: Affiliate profiles, referral links/attribution, campaigns, commissions/payables (reuses Ledger), loyalty/rewards, abandoned-cart/recommendation engines
- **Dependencies**: Ledger (done, for affiliate payables), Marketplace (done, for vendor-sponsored campaigns)
- **Why separate**: distinct bounded context from B2B/Auctions (marketing/attribution vs. transactional models)

### PROPOSED PHASE-20 — B2B, Auctions & Booking/Services (Alternative Commerce Models)
- **Master sections**: 16 (B2B/Auctions/Booking part)
- **Exists**: nothing
- **Remains**: company accounts/roles, RFQ/quotes/negotiated pricing/credit terms, auction bidding/settlement, booking calendars/resources/capacity
- **Dependencies**: Orders (done), Pricing (done), Payments (done)
- **Why separate**: three genuinely different transactional models bundled together deliberately to avoid three tiny phases — each is optional/niche relative to core commerce and can be resequenced independently by the owner

### PROPOSED PHASE-21 — Digital Products, Subscriptions & Store Value
- **Master sections**: 17 (Digital part), 15 (Wallet/Store Credit/Gift Card part)
- **Exists**: nothing beyond the `PreorderProductType`/`SubscriptionProductType`-style registry entries noted in Phase-14 audits
- **Remains**: digital delivery (signed downloads, license keys), subscription renewals/grace periods/dunning, Top-Up, Gift Card issuance/redemption, Wallet/Store Credit ledger account roles (extends Phase-10's Ledger, additively — new account roles, not a new ledger engine)
- **Dependencies**: Ledger (done — new account roles only), Catalog Product Types (done)
- **Why separate**: groups product-type-driven "stored value / access control" concerns that share licensing/balance mechanics, distinct from physical fulfillment

### PROPOSED PHASE-22 — POS & Omnichannel/Mobile
- **Master sections**: 20 (POS/Mobile part)
- **Exists**: Channel concept, Sanctum API groundwork across existing modules
- **Remains**: POS register/till/offline-sync, dedicated Customer/Vendor mobile API surfaces
- **Dependencies**: Catalog, Pricing, Inventory, Orders, Payments, RBAC (all done) — Master explicitly requires POS to reuse these rather than duplicate
- **Why separate**: distinct channel/UX surface, not a new commerce domain — safe to sequence late

### PROPOSED PHASE-23 — Licensing, SaaS & Feature Flags
- **Master sections**: 25
- **Exists**: nothing confirmed
- **Remains**: Entitlements/Capabilities model, Laravel Pennant integration, Platform/License/Tenant/Store/Vendor-Plan feature distinction
- **Dependencies**: none technically, but most valuable once the feature surface (Phases 15–22) is large enough to meter
- **Why separate**: platform-distribution concern, not a commerce domain; sequencing here is a business-priority judgment call for the owner, not an architectural dependency

### PROPOSED PHASE-24 — AI / MCP Platform Foundation
- **Master sections**: 23
- **Exists**: nothing
- **Remains**: AI Orchestrator, first-party MCP tool groups (Commerce/Developer/System/SEO/Content/Support/Vendor/Security), provider abstraction
- **Dependencies**: benefits from most commerce domains existing (Phases 01–22) so agents have real tools to call against
- **Why separate**: substantial standalone subsystem with its own provider/tool contracts

### PROPOSED PHASE-25 — Full Autonomous AI Control
- **Master sections**: 24
- **Exists**: nothing (depends entirely on §23)
- **Remains**: Disabled/Read-Only/Diagnose/Execute-With-Approval/Full-Autonomous modes, external kill switch, privileged-action audit trail
- **Dependencies**: Phase-24 (AI/MCP Foundation) must exist first
- **Why separate**: distinct safety/governance requirements (kill switch, approval gates) deserve isolated scope and review, not bundled with foundational AI plumbing

---

## Estimated remaining phase count

**11 proposed phases** (15–25) after Phase-14, covering the NOT STARTED / PARTIALLY COMPLETE rows in Part 1. This is this document's grouping recommendation, not a commitment — the owner may reorder, split, merge, or reprioritize any of them (e.g., Licensing/SaaS or POS could reasonably move earlier if commercial launch timing demands it).

---

*This document does not authorize implementation of any phase beyond Phase-14. It is informational only.*
