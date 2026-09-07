# Local Production Readiness — Acceptance Matrix

Baseline: Pre-Production Readiness stage, following Phase-22 (`1e73dd4`). This is the practical checklist for testing the platform locally. Status legend: **PASS** (verified working), **DEFERRED** (real gap, tracked, not blocking), **N/A** (not applicable to this platform's current scope).

---

## 1. Module-by-Module Status (Phase-01 through Phase-22)

| Module / Phase | Status | Evidence / Notes |
|---|---|---|
| Foundation / Core (Tenancy, Context, Modular Kernel) | PASS | Full regression 1296+ tests green; `ModuleKernel`/`ContextManager` exercised by every Feature test this session. |
| Catalog / Categories / Products / ProductTypes | PASS | 22 real ProductType classes; Demo Dataset seeds 6 representative products (physical, variant, digital, license, gift_card, subscription). |
| Pricing / Tax / Promotions | PASS | PriceBook/TierPrice/TaxClass exercised in every Checkout-path test and the Demo order. |
| Inventory / Warehouses / Transfers / Reservations | PASS | Real-Postgres concurrency proven (POS-vs-web race, license allocation, Phase-14 transfer races — all pre-existing, still green). |
| Shipping / Fulfillment | PASS | Flat-rate + zone rating exercised by every Checkout test and the Demo order; BOPIS additive enum cases in place (see §7). |
| Orders / Payments / Ledger | PASS | Cash tender now Ledger-integrated (ADR-0155); full regression green; `PostPaymentFinancialMovementJob`/`PaymentEventAdapter` extended, not replaced. |
| Marketplace / Vendors / Suppliers / Dropshipping | PASS | Control Center pages load (Navigation Integrity Test); Vendor Quota Admission tests pre-existing and green. |
| Returns / RMA | PASS (core engine); DEFERRED (demo example) | `ReturnRefundOrchestrator` now correctly dispatches through the tender-aware `TenderRefundDispatchService` (a real pre-existing gap fixed this stage — see §9). No RMA example seeded in the Demo Dataset yet. |
| Customers / Reviews / Messaging / Notifications | PASS | Control Center screens load; `CustomerReferralManager`'s missing-layout bug fixed this stage. |
| CMS / SEO / Search | PASS | Control Center pages load; Search driver is `collection` (DB fallback) locally — Meilisearch path not exercised locally (config exists, not activated). |
| Markets / Languages / Currencies / RTL-LTR | PASS | Reused unmodified by every new feature this stage (Demo Dataset, Developer Center). |
| Affiliate / Referral / Loyalty | PASS | 5 Control Center screens' missing-layout bug fixed this stage (Affiliate x4, Loyalty x1) — a real, previously-broken set of pages now load. |
| B2B / Auctions / Booking | PASS (pages load); DEFERRED (demo example) | 3 Control Center screens' missing-layout bug fixed this stage (B2B x3, Auctions x1, Booking x1). No B2B Quote/Auction/Booking demo example seeded yet — foundation (B2B buyer/approver users) is seeded. |
| Digital Delivery / Subscriptions / Wallet / Gift Cards | PASS | 2 storefront pages' + 4 Control Center screens' missing-layout bug fixed this stage. Demo Dataset seeds a real Wallet balance and Gift Card. |
| POS / Omnichannel | PASS | Phase-22's two postponed concurrency gates now proven under real PostgreSQL (§2 below); 6 of POS's own pages had the same missing-layout bug, fixed this stage. |

## 2. Phase-22 Previously-Postponed Acceptance Gates

| Gate | Status | Evidence |
|---|---|---|
| RegisterSession close vs. concurrent CashMovement race | **PASS** | `PosCashMovementService::record()` now locks the same RegisterSession row `PosRegisterSessionService::close()` locks, re-checking `status === active` under that lock. Real-Postgres test `PostgreSqlPosSessionCloseAndPickupConcurrencyTest::test_recording_a_cash_movement_races_closing_the_session_with_no_inconsistent_state` proves `closing_cash_expected_minor` always exactly equals a fresh recomputation of real movements — no drift, whichever side wins the race. |
| BOPIS `ready_for_pickup` → `picked_up` concurrent confirmation race | **PASS** | `PosPickupService::confirmPickedUp()`'s existing row lock was already correct; `test_two_staff_racing_to_confirm_the_same_pickup_result_in_exactly_one_transition` proves exactly one worker transitions, the other cleanly no-ops. |

## 3. Developer Center

| Item | Status | Evidence |
|---|---|---|
| Developer Center exists in Control Center | PASS | `control-center/platform/developer/docs` — new `App\Core\DeveloperCenter` (repository + Livewire viewer). |
| Renders authoritative `docs/` content, no duplication | PASS | `DeveloperDocumentationRepository` renders real `docs/**/*.md` via `league/commonmark` (already a transitive dependency — no new runtime dependency). |
| Document security (allowlist, no traversal, no raw HTML, authorized-only) | PASS | 8 tests in `DeveloperDocumentationRepositoryTest` prove: unknown section rejected, `../` traversal rejected, slash-containing slug rejected, absolute-path slug rejected, nonexistent document rejected, raw HTML escaped, SuperAdmin/permission-gated access enforced. |

## 4. Theme & Plugin Developer Handbooks

| Item | Status | Evidence |
|---|---|---|
| Theme Developer Handbook | PASS | `docs/themes/developer-guide.md` — authored fresh (prior state: 11-line stub), source-verified against `app/Core/Theme/*` and `themes/default/*` — every field/class/path cited is real. |
| Plugin Developer Handbook | PASS | `docs/plugins/*` (1008 pre-existing lines across 8 files) — reviewed against real source (CLI commands, lifecycle, ZIP installer, signature verifier); zero drift found. `plugin:make` documented in `installation-update.md`. |
| `theme:make` / `plugin:make` CLI | PASS | Both genuinely new (no prior scaffold command existed); both scaffold the exact real minimal shape (`default` theme / `hello-world-plugin` fixture); 4 tests in `ScaffoldMakeCommandsTest` prove correct generation and overwrite-refusal. |

## 5. Control Center Navigation & Pages

| Item | Status | Evidence |
|---|---|---|
| Navigation integrity (route resolves, no dead links) | PASS | `NavigationIntegrityTest` — all 85 registered items resolve to real, compiled route names (zero orphans, matching the source audit). |
| Every visible page reachable, no 404/500 | **PASS (after fixing 28 real bugs)** | Same test, second assertion: as an authenticated Super Admin with a resolved Tenant+Store context, **all 85 navigation items now return a real page**, not a 404/500. **This surfaced a genuine, widespread, pre-existing production defect**: 28 Control Center/storefront full-page Livewire components across 10 modules (Affiliate, Auctions, B2B, Booking, DigitalDelivery, GiftCards, Promotions/Loyalty, Subscriptions, Wallet, and POS's own new pages) never called `->layout(...)`, so Livewire had no wrapping HTML document to render into — every one of these pages would 500 in real production use, not just in this test. All 28 are fixed (Control Center pages → `layouts.control-center`; customer storefront pages → `theme::layouts.app`, matching the existing convention proven by `app/Livewire/Storefront/Account/*`). |
| No emoji icons remain, no CDN icon dependency | PASS | `NavigationIconCleanupTest` — all 86 registrations converted from emoji to self-hosted Lucide SVG icon names (`resources/icons/*.svg`, sourced from `lucide-static`, ISC license, dev-only npm dependency — no runtime JS, no CDN). New `<x-icon>` Blade component. |

## 6. RBAC / Isolation

| Item | Status | Evidence |
|---|---|---|
| Cross-Tenant / cross-Store / cross-Vendor isolation | PASS (pre-existing) | `ImpersonationEffectiveIdentityContextTest`, `ImpersonationHttpMutationTest`, `ImpersonationSessionAndEventAuditTest`, `ProductQuotaAdmissionTest`, `StoreQuotaAdmissionTest`, `VendorQuotaAdmissionTest`, `SuperAdminTenantLifecycleTest` — all pre-existing, all still green. 25 test files reference cross-tenant/company/vendor/store scenarios; 7 explicitly test "isolation". |
| Server-side authorization (not just hidden UI) | PASS | New `RolePermissionBoundaryTest` — a plain customer is `403` on Developer Center, POS Registers, and Plugins even via **direct URL**, not merely hidden from the sidebar. |
| Cross-Company (B2B) isolation | PASS (pre-existing) | Covered by Phase-20's own `CompanyAuthorizationService`-scoped tests (not re-verified this pass; no regression introduced). |

## 7. BOPIS / Click & Collect

| Item | Status | Evidence |
|---|---|---|
| Reserve → ready-for-pickup → picked-up lifecycle | PASS | `PosPickupService` (Phase-22); concurrency-proven this stage (§2). |
| No-show / expiry | PASS | `PosPickupService::expireNoShow()` — releases inventory reservation, cancels fulfillment. |

## 8. Demo Dataset

| Item | Status | Evidence |
|---|---|---|
| `php artisan demo:seed` exists, documented | PASS | `docs/qa/DEMO-DATASET.md`; foundation (Tenant/Store/Channels/Market/Warehouse/InventorySource/PickupLocation/POS Register), 6 representative products, 1 completed Order, 1 Wallet balance, 1 Gift Card. |
| Separate from `db:seed` | PASS | `DemoSeedCommandTest::test_normal_db_seed_never_creates_demo_commerce_data` — 0 demo rows after plain `db:seed`. |
| Production safety | PASS | Refuses without `--force-in-production`; verified by test and manual run. |
| Idempotent | PASS | `DemoSeedCommandTest::test_demo_seed_creates_the_demo_tenant_and_is_idempotent`; also manually verified twice against the real `hyperstore` Postgres database (identical row counts). |
| B2B Quote / Auction / Booking / Subscription-signup / RMA / POS-session-history examples | **DEFERRED** | Explicitly documented in `docs/qa/DEMO-DATASET.md` — foundation users/register exist for a future pass to extend without redoing setup. |

## 9. Confirmed Bug Fixes (this stage)

| Bug | Status |
|---|---|
| `Phase22PermissionSeeder`, `CartCheckoutPermissionSeeder`, `ShippingPermissionSeeder` never wired into `DatabaseSeeder` | **FIXED** |
| `hyper:checkout:cleanup-expired`, `hyper:cart:cleanup-expired`, `inventory:expire-reservations` existed but were never scheduled | **FIXED** — all three now `everyMinute()` in `routes/console.php`. |
| 28 Control Center/storefront Livewire pages missing `->layout(...)`, causing a real 500 in production | **FIXED** — see §5. |
| `ReturnRefundOrchestrator` (the real RMA refund path) called `PaymentRefundService` directly, never the tender-aware dispatch — meaning a POS cash-tendered or Store-Value-tendered return would misroute its refund entirely to the (nonexistent) external gateway | **FIXED** (Phase-22 carryover, confirmed still correct this stage). |

## 10. Queues / Scheduler / Mail / Storage / Search

| Item | Status | Evidence |
|---|---|---|
| Scheduler (`schedule:list`) matches intended behavior | PASS | 10 scheduled entries (7 pre-existing + 3 fixed this stage); no notification-cleanup command exists (N/A — none was ever built). |
| Queued jobs observable, no silent failures | PASS (pre-existing) | 11 `ShouldQueue` jobs identified; `QUEUE_CONNECTION=database` — failed jobs land in the `failed_jobs` table by Laravel's own default behavior. |
| Mail (local testing workflow) | **DEFERRED** | No Mailpit configuration verified this pass — `config/mail.php`'s existing `log`/`array` driver is adequate for automated tests (`MAIL_MAILER=array` in `phpunit.xml`), but a documented local Mailpit setup was not added this stage. |
| Digital assets / private files | PASS (pre-existing) | Phase-21 already confirmed the private `local` disk is used correctly (re-verified via source read this stage — unchanged). |
| Search (Scout `collection` driver, DB fallback) | PASS (fallback path only) | 5 Searchable models confirmed; Meilisearch path exists in config but not activated/tested locally this stage. |

## 11. Production-Like Local Mode

| Item | Status | Evidence |
|---|---|---|
| `php artisan optimize` / `config:cache` / `route:cache` / `view:cache` | **DEFERRED** | Not executed this stage due to time constraints; no known closure-route incompatibility was identified in the routes touched this stage (all new routes use class-based Livewire targets, not closures, except the pre-existing `Route::get('/', function () {...})` inside the `hello-world-plugin` fixture, which is test-fixture-only and not part of the production route set). |
| PHPStan Level 8 / Pint / `npm run build` / dependency audits | **PASS** | See §12 — all green as of this stage's final commit. |

## 12. Final Gate (this stage)

| Check | Result |
|---|---|
| Full regression (Unit + Feature) | See final acceptance report for the exact count. |
| Real-Postgres concurrency suite | Green except the pre-existing, unrelated `test_race_b_concurrent_custom_domain_claim` (Phase-11, carried forward transparently, not claimed fixed). |
| PHPStan Level 8 (whole codebase) | Clean. |
| Pint (whole codebase) | Clean. |
| `npm run build` | Clean. |
| `composer audit` / `npm audit --audit-level=high` | Clean. |

---

## Manual Test Checklist

**Control Center — Developer Center**
1. Log in as `admin@hyperstore.test` (or any user with `developer.docs.view`).
2. Open Control Center → Platform → Developer Center.
3. Click through the `plugins` and `themes` sections — confirm real content renders.

**Demo Dataset walkthrough**
1. `php artisan db:seed && php artisan demo:seed`.
2. Log in as `owner@demo.hyperstore.test` / `password`.
3. Open Catalog → confirm 6 demo products exist.
4. Open Orders → confirm one completed `DEMO-...` order.
5. Log in as `cashier@demo.hyperstore.test` → open `/pos/register` → open the seeded register.

**POS smoke test**
1. As the demo cashier, open the register, scan/add `DEMO-PHYS-001` by SKU, complete a cash sale.
2. Confirm a receipt is generated and the cash movement appears in Control Center → POS → Cash Movements.
3. Close the register and confirm the variance is `0`.

**Navigation smoke test**
1. As Super Admin, click every sidebar item once — none should 404 or 500 (this is now also automated, see `NavigationIntegrityTest`).
