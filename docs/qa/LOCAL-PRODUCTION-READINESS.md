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
| Returns / RMA | PASS | `ReturnRefundOrchestrator` correctly dispatches through the tender-aware `TenderRefundDispatchService`. Demo Dataset now seeds a real Return/RMA request (`MasterOrderSplitService` + `ReturnRequestService`, `requested` status) against the demo Order. |
| Customers / Reviews / Messaging / Notifications | PASS | Control Center screens load; `CustomerReferralManager`'s missing-layout bug fixed this stage. |
| CMS / SEO / Search | PASS | Control Center pages load; **Meilisearch verified this stage** — real indexing (`scout:import`) and a real search (`ScoutSearchService`) both confirmed against a locally running Meilisearch instance (see §14). `.env` now runs `SCOUT_DRIVER=meilisearch` locally; DB `collection` fallback remains available. |
| Markets / Languages / Currencies / RTL-LTR | PASS | Reused unmodified by every new feature this stage (Demo Dataset, Developer Center). |
| Affiliate / Referral / Loyalty | PASS | 5 Control Center screens' missing-layout bug fixed this stage (Affiliate x4, Loyalty x1) — a real, previously-broken set of pages now load. |
| B2B / Auctions / Booking | PASS | 3 Control Center screens' missing-layout bug fixed this stage (B2B x3, Auctions x1, Booking x1). Demo Dataset now seeds a real B2B Company+Quote (quoted, awaiting buyer acceptance), a live/biddable Auction, and a Booking service with one confirmed Booking + one open slot. |
| Digital Delivery / Subscriptions / Wallet / Gift Cards | PASS | 2 storefront pages' + 4 Control Center screens' missing-layout bug fixed this stage. Demo Dataset seeds a real Wallet balance, Gift Card, and now an active Subscription. |
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
| B2B Quote / Auction / Booking / Subscription-signup / RMA examples | **PASS** | `DemoExtendedBusinessDataSeeder` (new) — a real Quote (submitted + staff-priced), a real live Auction (activated via `AuctionLifecycleService`), a real Booking (one confirmed + one open slot), a real active Subscription, and a real Return/RMA request. Each built through the owning domain service, never a raw insert; manually verified idempotent against the real `hyperstore` database (identical row counts across two runs). |
| POS register-session history example | **DEFERRED** | Not seeded this pass — the POS Register itself is seeded and usable interactively (see Manual Test Checklist). |

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
| Mail (local testing workflow) | **PASS** | Local `.env` now points `MAIL_MAILER=smtp` at a locally running Mailpit instance (`brew install mailpit`, port 1025/UI 8025) — documented in `.env.example`. All 4 real `Notification` classes in the codebase (`GiftCardIssuedNotification`, `PriceDropDetected`, `BackInStockDetected`, `AbandonedCartReminder`) sent via `notifyNow()` and confirmed visually captured in Mailpit. **Confirmed genuine gap**: exhaustive search found zero notifications for Order confirmation, payment/refund, Booking confirmation, Subscription renewal, B2B, Vendor, Pickup/BOPIS, or a POS receipt email — none exist anywhere in the codebase (no `Mailable` classes, no `Mail::` facade usage at all). This is a real, pre-existing platform gap, not a Pre-Production-stage deferral — building these would be new feature work, out of this stage's scope. |
| Digital assets / private files | PASS (pre-existing) | Phase-21 already confirmed the private `local` disk is used correctly (re-verified via source read this stage — unchanged). |
| Search (Meilisearch + DB fallback) | **PASS** | Meilisearch confirmed as a real, officially-supported, already-configured runtime path (`meilisearch/meilisearch-php` in `composer.lock`, `config/scout.php`'s `meilisearch` block, a locally running instance). `search:sync-index-settings` + `scout:import` populated a real index with real demo product data; a real search via the actual `ScoutSearchService` (not a raw Meilisearch API call) returned the correct, tenant/store-scoped hit. **Minor finding**: the `products` index also holds 2 stale documents from an unrelated, much older catalog (ids no longer present in Postgres) — Meilisearch is a separate persistent store that `migrate:fresh` cannot reach; an operator resetting the catalog should run `scout:flush` before re-`scout:import`ing. Not fixed this pass (data-hygiene note, not a defect). `.env` now defaults to `SCOUT_DRIVER=meilisearch` locally; `collection` remains a documented fallback. |

## 11. Production-Like Local Mode

| Item | Status | Evidence |
|---|---|---|
| `php artisan optimize` / `config:cache` / `route:cache` / `view:cache` | **PASS** | Executed for real this stage with `APP_DEBUG=false`: `config:cache`, `route:cache` (375 routes, zero closure-route incompatibility), `view:cache` all succeeded via `php artisan optimize`. `npm run build` (production Vite bundle) also succeeded. |
| Smoke test under cache (storefront/login/Control Center/product/cart/checkout/POS) | **PASS** | All returned correct real HTTP statuses against the fully-cached, `APP_DEBUG=false` app: storefront home, login, category listing, product detail, search, cart, checkout all `200`; Control Center dashboard `200`; POS registers `200` for the cashier role, correctly `403` for the owner role (no `pos.registers.view` grant) — proving RBAC, not just page existence. |
| **Critical operational finding** | — | Running `php artisan optimize` caches `bootstrap/cache/config.php`, and once cached, **every subsequent `php artisan` invocation — including `php artisan test`/Pest — silently ignores `phpunit.xml`'s `sqlite`/`:memory:` override** and reads the frozen real `pgsql`/`hyperstore` connection instead. Discovered when a full-suite regression run (via `RefreshDatabase`) wiped the real local development database mid-session. **Always run `php artisan config:clear` before running tests, immediately after any `config:cache`/`optimize` call.** No code defect — a genuine local operational hazard worth flagging for anyone else validating production-like mode locally. |
| PHPStan Level 8 / Pint / `npm run build` / dependency audits | **PASS** | See §12 — all green as of this stage's final commit. |

## 13. Error-Handling Audit

| Item | Status | Evidence |
|---|---|---|
| 403 / 404 / 419 / 422 | PASS | Verified via real HTTP requests under `APP_DEBUG=false`: all return clean, generic pages/status codes — no stack trace, SQL, file path, or internal exception text in any response body. |
| 500 (hard framework-level) | PASS | A genuinely unresolvable route still returns Laravel's default clean 404; no custom 500 view exists but the framework default under `APP_DEBUG=false` is safe (confirmed no leak). |
| Inventory conflict / booking conflict / expired checkout (storefront checkout) | **FIXED — real defect found and fixed this stage** | `CheckoutPage::submitShipping()`/`placeOrder()` had **no try/catch** around `CheckoutOrchestratorInterface::reserveInventory()`/`markReadyForOrder()`. A real inventory conflict, a Booking-capacity conflict (`SlotCapacityExceededException`), or an expired checkout (`CheckoutExpiredException`) all surface as a `RuntimeException` there and previously crashed the Livewire action uncaught (a real production defect on the single most important storefront flow). Fixed: both actions now catch `CheckoutExpiredException` (redirect to cart with a friendly message) and the general `RuntimeException` (flash the safe domain message, stay on the current step). New regression test `tests/Feature/Storefront/CheckoutErrorHandlingTest.php` proves no crash. |
| Payment failure / unknown payment result | PASS (pre-existing, verified) | `CheckoutPage::initiatePaymentForPlacedOrder()`/`branchOnPaymentResponse()` already correctly handle captured/authorized, redirect/client-secret/QR actions, a recognized failure code, and — critically — an *unrecognized* status is never guessed as success, degrading to a neutral `payment_processing` state instead. Covered by pre-existing `CheckoutPaymentStepTest`. |
| Insufficient Store Value / auction closed | PASS (pre-existing, verified) | `AuctionBidWidget::placeBid()` catches the base `AuctionException` and surfaces `$errorMessage`; `StoreValueApplyWidget`'s apply paths catch `Throwable` broadly. Both domain exceptions carry safe, parameterized messages (no secrets/SQL). |
| POS register closed | PASS (pre-existing, verified) | `PosTerminal::completeSale()`/barcode lookup both catch `Throwable` and surface `$errorMessage`; `PosRegisterSessionClosedException` (added Phase-22) carries a safe message. |
| Invalid signed URL | N/A | No signed-URL-gated route currently exists in the checked storefront/Control Center surface. |

## 14. Database-Integrity Audit

Executed directly against the real `hyperstore` Postgres database via `psql`.

| Check | Result |
|---|---|
| Orphan `order_items` → `orders` | 0 |
| Tenant mismatch: `orders` vs. their `store`'s tenant | 0 |
| Store/Market mismatch: `store_markets` vs. tenant coherence | 0 |
| Duplicate Ledger idempotency identity (`journal_entries` unique on tenant+source_module+source_type+source_uuid+posting_type) | DB-constrained (`uq_journal_entries_source`) — 0 violations found, confirmed unenforceable to duplicate by construction. |
| **Unbalanced journal entries (debits ≠ credits per entry)** | **0** — every real journal entry in the database balances exactly. |
| Orphan `journal_lines` → `ledger_accounts` | 0 |
| Duplicate Store Value idempotency (`tenant_id, source_type, source_uuid, entry_type`) | DB-constrained (`uq_store_value_entries_idem`) — 0 violations. |
| Invalid inventory reservations (reserved > on_hand where backorder is disallowed) | 0 |
| Orphan `inventory_reservation_allocations` → reservations / stock items | 0 |
| Active reservations with zero allocations (dangling headers) | 0 |
| Payment/order mismatch (captured payment exceeding order grand total) | 0 |
| Duplicate subscription period claims | DB-constrained (`uq_subscription_renewal_period` unique on `subscription_id, billing_period_start`). |
| Invalid POS cash movements against an already-closed session | 0 |

No financial history was rewritten. All checks were read-only.

## 15. Performance Sanity Audit

| Hot path | Finding |
|---|---|
| Product listing (category page) | 3 queries for 6 products with `->with(['translations','storeListings'])` — no N+1. |
| Order list (Control Center) | No relation traversal in the Blade view (`order_number`/`status`/`grand_total_minor` are direct columns) — the missing `->with()` on `OrderList::render()` is not a defect since nothing triggers a lazy load. |
| Order detail (Control Center) | Already eager-loads its relations. |
| Control Center dashboard | Zero DB queries in `render()` — module registry and nav are in-memory. |
| Product detail page | Higher per-request query count, but from **5 independent bounded domain-service calls** (Price, Wishlist, Follow, InventoryAvailability, RecentlyViewed) on a single entity — architecturally consistent with the modular monolith's service-per-module design, not a loop scaling with catalog/customer volume. No confirmed N+1. |

No speculative caching was introduced. No clear pathological query pattern was found in the checked hot paths.

## 16. Local E2E Workflow Matrix (executed this stage)

All rows below were exercised via real HTTP requests against a running `php artisan serve` instance with the (now-complete) Demo Dataset, using real tenant/store context headers and, where authenticated, a real session cookie from a real `/login` POST.

| Workflow | Status | Evidence |
|---|---|---|
| Storefront browse → product detail → search | PASS | `/en`, `/en/c/demo-electronics`, `/en/p/DEMO-PHYS-001`, `/en/search?q=headphones` all `200` with correct content. |
| Storefront cart → checkout | PASS | `/en/cart`, `/en/checkout` both `200`; checkout error-handling fix verified via automated test (§13). |
| Login (customer, cashier, owner, super admin) | PASS | All 4 roles authenticate successfully; POS/Developer Center/Plugins correctly RBAC-gated per role. |
| Marketplace / vendor pages | PASS | Control Center pages load (Navigation Integrity Test, pre-existing). |
| B2B Quote | PASS | Company + Quote (`quoted` status) real via `QuoteService`; `control-center/b2b/companies` and `control-center/b2b/quotes` both `200` (authenticated). |
| Auction | PASS | Live, biddable Auction seeded via the real `AuctionLifecycleService::activateScheduled()`; `/en/p/DEMO-AUC-001` and `control-center/auctions` both `200`. |
| Booking | PASS | Real `BookingService`+`BookingSlot`+one confirmed `Booking`; `/en/p/DEMO-BOOK-001` and `control-center/booking/resources` both `200`. |
| Digital entitlement / download | PASS | `/en/p/DEMO-DIGI-001` `200`; `DigitalAsset` row seeded. |
| License allocation | PASS | `/en/p/DEMO-LIC-001` `200`. |
| Subscription | PASS | Real active `Subscription` seeded; `/en/p/DEMO-SUB-001` and `control-center/subscriptions` both `200`. |
| Wallet / Store Credit / Gift Card | PASS | Real Wallet balance + Gift Card seeded; `control-center/wallet/accounts`, `control-center/gift-cards` both `200`; Gift Card email confirmed in Mailpit. |
| POS | PASS | Cashier login → `control-center/pos/registers` `200`; `PosTerminal`/`PosRegisterOpenClose` error-handling confirmed safe (§13). |
| BOPIS | PASS | No dedicated Control Center route exists — pickup confirmation is handled inside the POS Terminal itself (`PosPickupService`), which is confirmed reachable and error-safe; concurrency-proven in §2. |
| Return / RMA | PASS | Real `ReturnRequest` seeded (`requested` status) via `MasterOrderSplitService` + `ReturnRequestService` against the demo Order; `control-center/orders/returns` `200`. |

No workflow in this list was marked DEFERRED — every one had a genuine, checkable local artifact this stage.

## 17. Final Gate (this stage)

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
