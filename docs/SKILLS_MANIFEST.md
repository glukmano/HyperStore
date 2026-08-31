# Agent Skills Manifest

This manifest documents all mandatory project-local Agent Skills generated for the **Hyper Commerce Platform** in accordance with [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) Section 29.

Both **Google Antigravity** (`.agents/skills/`) and **Claude Code** (`.claude/skills/`) maintain 100% equivalent skill sets synchronized via [`scripts/sync-skills.sh`](file:///Volumes/Lukman/dev/Projects/HyperStore/scripts/sync-skills.sh).

---

## Skills Catalog (24 Mandatory Skills)

| # | Skill Name | Antigravity Path | Claude Code Path | Master Plan Section | Primary Responsibility & Triggers |
| :-: | :--- | :--- | :--- | :--- | :--- |
| 1 | `project-governance` | `.agents/skills/project-governance/` | `.claude/skills/project-governance/` | 0, 1, 33, 35, 36, 38 | Enforces phase boundaries, Master Plan supreme authority, change classification (A-E), and Definition of Done. |
| 2 | `architecture-boundaries` | `.agents/skills/architecture-boundaries/` | `.claude/skills/architecture-boundaries/` | 3.1, 3.2, 3.3, 7, 21 | Enforces Modular Monolith architecture, Core/Module/Plugin separation, DTO/contract boundaries, and avoids microservices or scattered conditionals. |
| 3 | `laravel-platform` | `.agents/skills/laravel-platform/` | `.claude/skills/laravel-platform/` | 6, 22, 26 | Enforces Laravel 13 idioms, PHP 8.4+ strict typing, thin controllers, Livewire component boundaries, Pest tests, Pint formatting, and Larastan Level 8+. |
| 4 | `postgresql-data-design` | `.agents/skills/postgresql-data-design/` | `.claude/skills/postgresql-data-design/` | 6, 14, 26 | Enforces PostgreSQL relational data integrity, transactions, concurrency locking (preventing oversell), indexing, JSONB hygiene, and safe migrations. |
| 5 | `multi-tenancy` | `.agents/skills/multi-tenancy/` | `.claude/skills/multi-tenancy/` | 4, 8, 25, 26, 27 | Enforces TenantContext resolution, query isolation, cross-tenant leak prevention, and automated isolation testing. Defers physical tenancy DB model. |
| 6 | `multi-store-context` | `.agents/skills/multi-store-context/` | `.claude/skills/multi-store-context/` | 4, 8, 9, 20 | Enforces Store/Channel/Market/Vendor contexts, canonical multi-store shared catalog listings, and store-specific price/visibility overrides. |
| 7 | `commerce-domain` | `.agents/skills/commerce-domain/` | `.claude/skills/commerce-domain/` | 13, 17, 26 | Enforces Cart, Checkout, Master Order, Seller/Vendor Order, Fulfillment, Shipment, and Return/RMA state machines and entity separation. |
| 8 | `catalog-product-types` | `.agents/skills/catalog-product-types/` | `.claude/skills/catalog-product-types/` | 9, 10 | Enforces extensible Product Types via Strategy pattern (20+ types), Attributes, Attribute Sets, Variants, and faceted search indexing. |
| 9 | `money-ledger` | `.agents/skills/money-ledger/` | `.claude/skills/money-ledger/` | 6, 15, 26, 27 | Enforces strict No-Float rule (minor units / `brick/money`), immutable double-entry ledger balances, commission calculations, payouts, and idempotency. |
| 10 | `marketplace-vendors` | `.agents/skills/marketplace-vendors/` | `.claude/skills/marketplace-vendors/` | 4, 11, 12 | Enforces unified User identity, Vendor onboarding, approval policies, plan entitlements, vendor multi-staff RBAC, and storefront slug routing. |
| 11 | `fulfillment-dropshipping` | `.agents/skills/fulfillment-dropshipping/` | `.claude/skills/fulfillment-dropshipping/` | 13, 14, 21 | Enforces Fulfillment Core, first-party Dropshipping module, supplier connector plugins, multi-supplier SKU routing, automated fallback, and POD. |
| 12 | `affiliate-marketing` | `.agents/skills/affiliate-marketing/` | `.claude/skills/affiliate-marketing/` | 15, 16 | Enforces first-party Affiliate program, referral links/codes, attribution windows/models (first/last/coupon), commission ledger events, and fraud checks. |
| 13 | `localization-markets-rtl` | `.agents/skills/localization-markets-rtl/` | `.claude/skills/localization-markets-rtl/` | 6, 19, 22 | Enforces N-language dynamic management, Markets abstraction, multi-currency, UTC timestamps, and strict logical Tailwind CSS (`ms`, `me`, `ps`, `pe`, `start`, `end`). |
| 14 | `api-webhooks` | `.agents/skills/api-webhooks/` | `.claude/skills/api-webhooks/` | 3.2, 20, 26 | Enforces REST API design, Sanctum auth, rate limiting, versioning, standard JSON envelopes, and HMAC SHA-256 signed webhooks with replay protection. |
| 15 | `realtime-messaging` | `.agents/skills/realtime-messaging/` | `.claude/skills/realtime-messaging/` | 6, 18 | Enforces Laravel Reverb WebSockets, Buyer-Seller chat, DB persistence before broadcast, presence/private channel authorization, and abuse controls. |
| 16 | `plugin-sdk` | `.agents/skills/plugin-sdk/` | `.claude/skills/plugin-sdk/` | 3.3, 21, 26 | Enforces first-party Plugin SDK, strict `plugin.json` manifests, permission boundaries, hook lifecycle (install/enable/disable/uninstall), and zero core edits. |
| 17 | `theme-sdk` | `.agents/skills/theme-sdk/` | `.claude/skills/theme-sdk/` | 6, 22 | Enforces `themes/default` baseline, Child Themes, reusable `<x-ui.*>` component abstractions, Page Builder blocks, and clean separation from raw daisyUI. |
| 18 | `ai-agents-mcp` | `.agents/skills/ai-agents-mcp/` | `.claude/skills/ai-agents-mcp/` | 23, 24, 26 | Enforces Laravel AI SDK / Laravel MCP multi-agent platform, Autonomy governance (default OFF), external Kill Switch (`php artisan ai:kill`), and audit trails. |
| 19 | `security-hardening` | `.agents/skills/security-hardening/` | `.claude/skills/security-hardening/` | 24, 26, 27 | Enforces defense-in-depth, 2FA for privileged roles, CSRF/XSS protection, SSRF-safe HTTP client wrapper, secure file MIME validation, and secret masking. |
| 20 | `testing-quality` | `.agents/skills/testing-quality/` | `.claude/skills/testing-quality/` | 6, 27, 35 | Enforces Pest test suites, Larastan Level 8+, Pint formatting, tenant/store isolation tests, financial invariant assertions, and test immutability. |
| 21 | `performance-observability` | `.agents/skills/performance-observability/` | `.claude/skills/performance-observability/` | 6, 26 | Enforces N+1 query prevention, deliberate indexing, Redis caching boundaries (ephemeral only), Horizon queue supervision, and Pulse telemetry. |
| 22 | `seo-commerce` | `.agents/skills/seo-commerce/` | `.claude/skills/seo-commerce/` | 6, 11, 18, 19 | Enforces first-party SEO, JSON-LD structured data (`Product`, `BreadcrumbList`), canonical URLs, XML sitemaps, `hreflang`, and vendor domain SEO. |
| 23 | `devops-release` | `.agents/skills/devops-release/` | `.claude/skills/devops-release/` | 6, 26, 34 | Enforces Git flow, environment progression (Local/Dev/Staging/Prod), CI quality gates, zero-downtime migrations, and automated backup restoration. |
| 24 | `documentation-adr` | `.agents/skills/documentation-adr/` | `.claude/skills/documentation-adr/` | 0, 6, 28, 35 | Enforces documentation maintenance, ADR lifecycle in `docs/decisions/`, RFC proposals in `docs/proposals/`, and dependency registration in `docs/DEPENDENCIES.md`. |

---

## Synchronization Tooling

To ensure both coding agents (Antigravity and Claude Code) operate with identical skill specifications:

```bash
# Verify equivalence between .agents/skills and .claude/skills
./scripts/sync-skills.sh --check

# Synchronize modifications from Antigravity to Claude Code
./scripts/sync-skills.sh --sync-to-claude

# Synchronize modifications from Claude Code to Antigravity
./scripts/sync-skills.sh --sync-to-agents
```
