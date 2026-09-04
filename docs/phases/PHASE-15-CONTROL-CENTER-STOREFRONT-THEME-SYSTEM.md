# PHASE-15: Control Center & Storefront Theme System

> **Authority**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) — Section 12 (Control Center / Super Admin), Section 22 (Theme System)
> **Status**: COMPLETED — ACCEPTANCE CANDIDATE (implementation finished, all gates green; owner acceptance pending — not self-marked owner-accepted)
> **Active Dates**: 2026-09-04 to 2026-09-04

---

## 1. Objective

Close the UI-completion gap left open by Phases 01–14: build the missing operational Control Center UI over the already-implemented commerce backend, and deliver the first Storefront + Theme Core (Master §22), all under a single unified Control Center shell (Master §12) with no second admin/vendor application and no custom design system. Authorized by owner directives "PHASE-15 — CONTROL CENTER & STOREFRONT THEME SYSTEM" (source audit + plan) and "PHASE-15 OWNER DELTA — APPLY AND IMPLEMENT NOW" (2026-09-04), following the frozen plan at the owner-approved planning session (source-audit summary reproduced in §2 below).

## 2. Source Audit Summary (frozen at plan approval)

Confirmed by direct source read before this phase began: the Control Center was a Phase-01 foundation shell only (single navbar layout, 5 `<x-ui.*>` components, no sidebar/nav registry, RBAC installed but wired into zero views/routes/policies). Per-module UI coverage was PARTIAL-to-MISSING almost everywhere despite complete backends (Catalog/Pricing/Promotions: create+list only, no delete; Inventory: 9 real backend actions behind inert placeholder views; Fulfillment/Shipping: real components orphaned with no `Routes/web.php`; Order/Payment/Marketplace/Dropshipping: zero UI). Storefront and Theme System were 100% unbuilt (no `themes/` directory beyond ADR-0006's boundary record, no public routes, no Search module, no customer-auth backend).

## 3. Included Scope

- **Control Center foundation**: expanded `<x-ui.*>` component library (table, modal, drawer, tabs, dropdown, select, textarea, checkbox, radio, pagination, stats, breadcrumbs, empty-state, confirm-dialog, toast — daisyUI 5 defaults only); `App\Core\Navigation` module-contributed, permission-filtered sidebar registry; rebuilt shell layout (responsive drawer sidebar, breadcrumbs-ready); rebuilt Dashboard; consistent route middleware (`web`, `auth`, `ResolveContextMiddleware`) across module `Routes/web.php` files; shared `errors/403` view.
- **Theme SDK** (`app/Core/Theme/`, first implementation of the ADR-0006-declared boundary): `ThemeManifest`/`ThemeRegistry`/`ThemeResolver`/`ResolvedTheme` + `ResolveStorefrontThemeMiddleware`. Owner Delta (2026-09-04) requirements, all included:
  1. Store-aware active-theme resolution (`stores.active_theme` column, additive migration) — never a hardcoded `'default'` literal in Storefront Core.
  2. Stable Plugin-SDK-ready registration seams on both `ThemeRegistryInterface::register()` and `NavigationRegistryInterface::register()` — the Plugin SDK itself is NOT built this phase.
  3. Safe multi-level inheritance chain resolution: cycle detection, missing-parent handling, bounded max depth (5), deterministic fallback to `default`. Only `themes/default` ships.
  4. No temporary Search implementation of any kind; a disabled/hidden search affordance only.
- **`themes/default`**: manifest, `layouts/app.blade.php`, `pages/` (home, category, product, cart, checkout, order-confirmation, order-lookup, vendor-storefront), `sections/product-types/default.blade.php` (generic capability-driven product template), `components/product-card.blade.php`.
- **Storefront Core**: public routes + thin `App\Livewire\Storefront\*` components composing existing Cart/Checkout/Catalog/Pricing/Order/Marketplace services only (`CartServiceInterface`, `CheckoutOrchestratorInterface`, `OrderCreationServiceInterface`, `PriceResolverInterface`, `VendorStorefrontResolverInterface`) — home → category → product (with `ProductTypeInterface::getStorefrontTemplate()`, additive) → cart → checkout (customer → address → shipping → review/place order) → order confirmation → guest order lookup → vendor storefront.
- **Reconnection of orphaned admin UI**: Fulfillment and Shipping `Routes/web.php` + navigation registration; completion of Shipping's 6 stub views.
- **Completion of Inventory's 9 inert admin views** over the existing Phase-05/14 backend (Warehouses, Sources, Stock, Transfers, Adjustments, Receiving, Reconciliation, Movement History, Reservations).
- **Net-new admin screens over existing backend only**: Orders (list/detail) and RMA disposition (`modules/Order`); Marketplace Vendor list/detail with status actions (`modules/Marketplace`); Core platform screens — Stores, Markets, Channels, Tenant Settings, Users/Roles/Permissions (first real UI consumer of the already-installed `spatie/laravel-permission`).
- Permission seeders for every newly UI-exposed domain lacking one (Order, Marketplace, platform/Core), following the existing `{resource}.{action}` convention, and the codebase's established in-component `abort(403)` authorization pattern (not a new route-middleware convention).

## 4. Explicitly Excluded Scope

- Customer authentication/account UI (no Customer identity/auth backend exists).
- Any Search implementation, temporary or otherwise (Owner Delta #4) — Search remains owned by its own future phase (Scout/Meilisearch, Master §18/§19).
- Theme Marketplace, Page Builder, multiple shipped themes, actual Plugin SDK implementation (registration contracts only, per Owner Delta #2).
- Suppliers/Dropshipping/Purchase-Order admin UI, Payments read-only viewer, full CRUD (edit/delete) completion for Catalog/Pricing/Promotions beyond what already existed — deferred; not started this phase, tracked as open follow-up (see final acceptance report).
- Any Phase-16+ business feature (B2B, POS, Affiliate, AI/MCP, full CMS, Localization/Markets subsystem).
- Warehouse-bound PO receiving UI (no backend; Phase-14 deferred it).
- Backend redesign of any Phase 01–14 domain.

## 5. Required Skills

`project-governance`, `laravel-platform`, `multi-tenancy`, `commerce-domain`, `testing-quality`.

## 6. Prerequisites

Phases 01–14 (all COMPLETED). Depends on: Catalog `ProductTypeRegistryInterface` (Phase-03), Cart/Checkout services (Phase-07), Order/Fulfillment/RMA (Phase-08/13/14), Inventory Transfer services (Phase-05/14), Marketplace Vendor domain (Phase-11), `spatie/laravel-permission` (installed, unwired until this phase).

## 7. Architecture & ADRs

Adheres to: ADR-0006 (Theme and Plugin Isolation — this phase is its first implementation), Master §12 (Control Center unified-shell rule), Master §22 (Theme System). Creates: ADR-0130 (Theme SDK: store-aware resolution, inheritance-chain safety, Plugin-ready registration seam), ADR-0131 (Navigation Registry: module/Plugin-shared registration contract), ADR-0132 (Storefront Core boundary: thin Livewire composition over existing services only, no new domain logic).

## 8. Database Work

Additive only: `stores.active_theme` (string, default `'default'`, nullable-safe). No other schema change — every admin/storefront screen is built against existing tables/services.

## 9. Backend Work

`App\Core\Navigation\{NavigationRegistry,Contracts\NavigationRegistryInterface,DTOs\NavigationItem}` (new). `App\Core\Theme\{ThemeRegistry,ThemeResolver,Contracts\*,DTOs\*,Http\Middleware\ResolveStorefrontThemeMiddleware}` (new). `Modules\Catalog\Contracts\ProductTypeInterface::getStorefrontTemplate()` (additive, default `'default'` on `ProductTypeDefinition`). `Modules\Inventory\Livewire\TransferManager` gains `createTransfer()`/`dispatchTransfer()`/`receiveTransfer()` calling the existing `InventoryTransferServiceInterface` (Phase-14) — no new service logic. New permission seeders: `OrderPermissionSeeder`, `MarketplacePermissionSeeder`, `PlatformAdminPermissionSeeder`.

## 10. Frontend Work

Full scope described in §3. All Blade/Livewire work uses only `<x-ui.*>` + daisyUI 5 defaults; RTL/LTR via logical Tailwind utilities exclusively.

## 11. API Work

None — this phase adds UI/routes over existing service interfaces; no new REST endpoints. Existing `routes/web.php` JSON mutation closures (store creation, platform settings) are left untouched; new UI screens call the same underlying services directly.

## 12. Security

Tenant scoping via `ContextManager` on every new screen (matching the established `WarehouseManager` inline-resolution pattern). In-component `abort(403)` permission checks on every mutating action, using seeded permission names only — no permission name invented without a seeder entry. Shared `errors/403` view replaces Laravel's default. No new cross-tenant surface introduced.

## 13. Tests

Pest feature tests per new/reconnected admin screen (list renders, create succeeds, permission-denied path returns 403). Storefront happy-path feature test (home → category → product → cart → checkout → confirmation) against real services. `ThemeResolver` unit tests: cycle detection, missing-parent fallback, max-depth enforcement, deterministic fallback to `default`, Store-aware resolution. RTL static-scan test asserting no physical-direction Tailwind utility class in `resources/views/components/ui/` or `themes/`. Full existing regression suite stays green.

## 14. Acceptance Criteria

- [x] All unit and feature tests pass, 100% green — 751/751 passing, 3891 assertions (full default `Unit`+`Feature` suite).
- [x] Larastan/PHPStan Level 8, zero errors (full project).
- [x] Laravel Pint clean (full project, `--test`).
- [x] Theme resolution is Store-driven; inheritance-chain safeguards (cycle/missing-parent/max-depth/fallback) proven by 10 dedicated unit tests (`tests/Unit/Theme/ThemeResolverTest.php`).
- [x] No `/search` route or interim search query logic anywhere in the diff — verified by source grep; the default theme's header search input is rendered `disabled`.
- [x] Zero custom CSS/design system introduced (daisyUI/Tailwind defaults only) — verified by source grep for `style="` across all new/touched Blade files (none found).
- [x] No second admin/vendor application; single Control Center shell/prefix (`/control-center/*`) confirmed via `route:list`.
- [x] RTL/LTR: static-scan test (`tests/Unit/Theme/RtlLogicalDirectionScanTest.php`) proves zero physical-direction Tailwind classes across `resources/views/components/ui/` and `themes/`.
- [x] `PROJECT_MASTER_PLAN.md` unchanged (`git diff` empty); Phase 01–14 contracts unchanged except the additive `ProductTypeInterface::getStorefrontTemplate()` method and one bug fix (`Modules\Shipping\Livewire\RateRuleManager` queried a non-existent `shipping_rate_rules.tenant_id` column — discovered because this phase's own navigation now makes that screen reachable; fixed to query through the existing `ShippingMethod.tenant_id` relation instead — no schema change).
- [x] Migration rollback/re-apply verified (`migrate:rollback --step=1` then `migrate`, clean both directions).
- [x] `npm run build` — clean. `composer audit` — 0 vulnerabilities. `npm audit --audit-level=high` — **could not complete**: this sandboxed environment has no network route to `registry.npmjs.org` (confirmed via two independent attempts, both timing out identically; `composer audit` succeeded because Composer's own advisory check reached its endpoint). This is an environment limitation, not a code defect — flagged explicitly rather than fabricating a result; the owner should re-run `npm audit --audit-level=high` from a network-unrestricted environment before final release sign-off.

## 15. Stop Condition

When all acceptance criteria are satisfied: run all tests/linters, produce the Phase-15 completion report, commit, push to `origin/main` (no force push), and **STOP and wait for user instruction before beginning Phase-16**.
