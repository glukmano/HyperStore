# PHASE-01: Platform Foundation & Modular Monolith Kernel

> **Authority**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md)  
> **Status**: ACTIVE  
> **Active Date**: 2026-08-31  

---

## 1. Objective

Establish the core technical foundation of the **Hyper Commerce Platform**:
- Install and configure Laravel 13, PHP 8.4+, PostgreSQL, Redis, Livewire 4, Tailwind CSS 4, daisyUI 5, Laravel Sanctum, Laravel Pennant, Spatie Permission (v8), Spatie Activitylog (v5), and Pest 5 / Larastan / Pint.
- Build the custom, project-owned Modular Monolith Kernel and Registry (without `nwidart/laravel-modules` or other third-party module frameworks).
- Build Core context hierarchy interfaces and DTOs (`TenantContext`, `StoreContext`, `ChannelContext`, `MarketContext`, `LocaleContext`, `CurrencyContext`, `UserContext`) with abstract resolvers and safe null/unresolved fallback behavior without hardcoding routing/database assumptions.
- Implement dynamic localization with bidirectional RTL/LTR support and `<html dir="...">` injection using Tailwind 4 logical utility classes.
- Wrap Feature Flags (Pennant) and Audit logging (Activitylog) in clean platform contracts.
- Provide minimal `<x-ui.*>` Blade component abstractions and a minimal Control Center validation shell using standard daisyUI/Tailwind defaults.
- Prove module kernel architecture via test fixtures under `tests/Fixtures/Modules/` while leaving production `modules/` empty of commerce logic.
- Enforce No-Float Money and Multi-Store Product architecture rules via ADRs.

---

## 2. Included Scope

1. **Framework & Runtime Setup**:
   - Initialize Laravel 13 / PHP 8.4+ project.
   - Configure PostgreSQL as authoritative relational database and Redis for caching/sessions.
2. **Modular Monolith Infrastructure**:
   - Project-owned lightweight `ModuleKernel` and `ModuleRegistry` in `app/Core/Modular/`.
   - Module contracts: `ModuleInterface`, `ModuleKernelInterface`, `ModuleRegistryInterface`, `ModuleManifest`.
   - Base `ModuleServiceProvider` with conventions for routes, views, translations, configs, and migrations.
   - Module lifecycle, autodiscovery, and topological dependency ordering.
   - Test fixture modules in `tests/Fixtures/Modules/` to test and prove kernel mechanics without cluttering production `modules/`.
3. **Core Context Hierarchy**:
   - Abstract context interfaces: `TenantContextInterface`, `StoreContextInterface`, `ChannelContextInterface`, `MarketContextInterface`, `LocaleContextInterface`, `CurrencyContextInterface`, `UserContextInterface`.
   - Immutable context DTOs / value objects.
   - `ContextManager` container and abstract resolver contracts (`TenantResolverInterface`, `StoreResolverInterface`, etc.) with safe null behavior.
   - Request context resolution middleware that delegates solely to abstract resolvers without hardcoding domain/subdomain/session/database assumptions.
4. **Dynamic Localization & RTL/LTR**:
   - `LocaleManagerInterface` and `LocaleManager` supporting dynamic N-languages (Arabic and English baseline).
   - Text direction enum (`Direction::LTR`, `Direction::RTL`) and `<html dir="...">` injection.
   - Logical CSS conventions in Tailwind CSS 4 (`@tailwindcss/vite`, `@import "tailwindcss";`, `@plugin "daisyui";`).
5. **RBAC, Feature Flags & Audit Foundations**:
   - Spatie Permission v8 configuration and middleware.
   - Laravel Pennant wrapped via `FeatureManagerInterface` / `FeatureManager`.
   - Spatie Activitylog v5 configuration wrapped via `AuditManagerInterface` / `AuditManager`.
6. **Reusable UI & Minimal Control Center Shell**:
   - Reusable `<x-ui.*>` Blade components (`button`, `card`, `input`, `select`, `table`, `modal`, `badge`, `alert`).
   - Minimal Control Center layout `resources/views/layouts/control-center.blade.php`.
   - Livewire 4 component `App\Livewire\ControlCenter\DashboardOverview` to validate reactivity, RTL/LTR toggle, and context status display.
   - Health / status ping endpoint (`/up`).
7. **Testing & Quality Tooling**:
   - Pest 5 ecosystem (`pestphp/pest:^5.0`, `pestphp/pest-plugin-laravel:^5.0`).
   - Larastan Level 8+ analysis (`larastan/larastan:^3.0`).
   - Laravel Pint code style formatting (`laravel/pint:^1.0`).
8. **ADRs & Documentation**:
   - Foundational ADRs: Modular Monolith, PostgreSQL Source of Truth, Custom Module Kernel, No-Float Money Invariant (ADR-only), Canonical Multi-Store Products (ADR-only), Theme & Plugin Isolation Principles.
   - Complete `docs/DEPENDENCIES.md` with exact verified versions.

---

## 3. Explicitly Excluded Scope

- ❌ Business commerce implementations: Products, Categories, Attributes, Inventory, Cart, Checkout, Orders, Fulfillment, Payments, Ledger balances, Vendors, Marketplace, Dropshipping, Suppliers, Shipping, Taxes, Affiliate, POS, Subscriptions, Bookings, Auctions.
- ❌ Permanent production modules (e.g. no `modules/Skeleton`; test fixtures live in `tests/Fixtures/Modules/`).
- ❌ Installing `brick/money` in Phase 01 (deferred to Pricing/Ledger phase; only ADR rule defined now).
- ❌ Premature resolution of physical SaaS multi-tenancy database strategy (database-per-tenant, schema-per-tenant, etc. remains deferred).
- ❌ Premature domain/subdomain/tenant routing strategies.
- ❌ Third-party module frameworks (e.g. `nwidart/laravel-modules` is strictly forbidden).
- ❌ AI Agents / MCP server business tools.
- ❌ Custom or flashy theme designs (only standard daisyUI/Tailwind defaults).
- ❌ Advance to PHASE-02.

---

## 4. Required Skills

- `project-governance`
- `architecture-boundaries`
- `laravel-platform`
- `postgresql-data-design`
- `multi-tenancy`
- `multi-store-context`
- `localization-markets-rtl`
- `security-hardening`
- `testing-quality`
- `documentation-adr`

---

## 5. Prerequisites

- Phase 00 (Governance & Skills Bootstrap) completed and committed.
- PHP 8.4+, Composer, Node.js, PostgreSQL, and Redis running in local environment.

---

## 6. Architecture & ADRs

- [ADR-0001: Modular Monolith Architecture Pattern](file:///Volumes/Lukman/dev/Projects/HyperStore/docs/decisions/ADR-0001-modular-monolith-architecture.md)
- [ADR-0002: PostgreSQL as Relational Source of Truth](file:///Volumes/Lukman/dev/Projects/HyperStore/docs/decisions/ADR-0002-postgresql-source-of-truth.md)
- [ADR-0003: Project-Owned Lightweight Module Kernel](file:///Volumes/Lukman/dev/Projects/HyperStore/docs/decisions/ADR-0003-project-owned-module-kernel.md)
- [ADR-0004: Strict Non-Floating Point Monetary Invariant](file:///Volumes/Lukman/dev/Projects/HyperStore/docs/decisions/ADR-0004-strict-no-float-money.md)
- [ADR-0005: Canonical Multi-Store Product Architecture](file:///Volumes/Lukman/dev/Projects/HyperStore/docs/decisions/ADR-0005-canonical-multi-store-products.md)
- [ADR-0006: Theme and Plugin Isolation Principles](file:///Volumes/Lukman/dev/Projects/HyperStore/docs/decisions/ADR-0006-theme-and-plugin-isolation.md)

---

## 7. Acceptance Criteria

- [ ] Laravel 13 framework initialized with PHP 8.4+ strict typing.
- [ ] PostgreSQL and Redis configured and operational.
- [ ] Project-owned Module Kernel dynamically discovers and registers modules without third-party module frameworks.
- [ ] Module kernel mechanics proved via `tests/Fixtures/Modules/` test suites.
- [ ] Core context interfaces, DTOs, and abstract resolver contracts operational with safe null fallback behavior.
- [ ] Dynamic localization with RTL/LTR direction switching verified.
- [ ] Reusable `<x-ui.*>` components rendering in Livewire 4 with daisyUI 5 and Tailwind CSS 4.
- [ ] Spatie Permission v8, Spatie Activitylog v5, and Laravel Pennant integrated through platform abstractions.
- [ ] Pest 5 test suites passing with 100% green status.
- [ ] Larastan Level 8+ passing with 0 errors; Laravel Pint clean.
- [ ] All 6 required ADRs written and accepted in `docs/decisions/`.
- [ ] `docs/DEPENDENCIES.md` updated with exact verified installed package versions (and `brick/money` omitted).
- [ ] Zero commerce business features implemented.

---

## 8. Stop Condition

Upon satisfying all acceptance criteria:
1. Run all test suites, static analysis, and styling tools.
2. Produce a detailed Phase 01 completion report.
3. Commit progress to Git.
4. **STOP and wait for Phase 02 specification.**
