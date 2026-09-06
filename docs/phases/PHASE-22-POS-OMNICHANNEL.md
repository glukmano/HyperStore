# PHASE-22 — POS / OMNICHANNEL

**Status: APPROVED, IMPLEMENTED.** Baseline: Phase-21 closed (`b176a4c`).

Authority: `PROJECT_MASTER_PLAN.md` §20 (POS is first-party, reuses Catalog/Pricing/Inventory/Customers/Orders/Payments/Returns/RBAC — never a second commerce core). This document is the final, owner-corrected design (source audit + 14-item Owner Delta applied) for the module actually built.

## 1. Scope

New top-level module `modules/POS` (`Modules\POS`): Register, RegisterSession, CashMovement, POS sale orchestration on top of the existing Cart/Checkout/Order/Payment/Inventory pipeline, manual-discount audit, receipts, cross-store return policy, BOPIS lifecycle completion, POS Control Center + terminal UI. Additive extensions only to Payment (cash tender), Ledger (cash-on-hand account role), Fulfillment (pickup mode/status), Checkout (manual-discount seam, POS context snapshot), Order (POS/cashier/pickup snapshot columns), and the refund-dispatch boundary (tender-neutral orchestration seam).

**Explicitly excluded**: Phase-23/24/25, Developer Center, Control-Center redesign, icon migration, native mobile POS, hardware drivers/SDKs, offline-first/sync, microservices, exchanges, subscription/B2B/Auction/Booking purchase at POS (deferred).

## 2. Owner Delta — Applied Corrections (all 14 items)

1. **Register binds to an exact Market via `StoreMarket`, not an ambiguous free `market_id`.** `pos_registers.store_market_id` → `store_markets.id` (must be `is_active=true`). Register context resolution proves: Tenant → Store → active StoreMarket → Market → allowed Currency → POS Channel → InventorySource, all in one query chain. `RegisterSession.currency` is validated against that exact Market's `MarketCurrency` set at session-open time. No implicit Market selection anywhere.
2. **POS Order context is hard-fail, not soft-optional.** `Modules\POS\Contracts\PosOrderContextHookInterface::validateAndFreezePosContext(Order $order)` is invoked from `OrderCreationService::createFromCheckout()` **without** a try/catch — mirroring the B2B `CompanyOrderCreditHookInterface` precedent exactly, never the Affiliate soft-hook pattern. A closed/invalid RegisterSession, an unauthorized cashier, or a Store/Market mismatch throws and rolls back the entire Order-creation transaction. Non-POS Orders are entirely unaffected (the hook no-ops when the checkout carries no `pos_context_snapshot`).
3. **Cash-drawer source of truth fixed.** The `opening_float` `PosCashMovement` row is authoritative; `pos_register_sessions.opening_cash_minor` is an immutable snapshot written in the same transaction as that movement, never independently editable. `closing_count` is **removed** as a movement type — a physical count is not a cash-in/out event. `closing_cash_counted_minor` lives only on `pos_register_sessions` (the closing snapshot). Expected cash is derived exclusively from `SUM(amount_minor)` over `opening_float/sale_cash_in/refund_cash_out/paid_in/paid_out` movements; `variance = counted − expected`.
4. **Cash Ledger accounting is source-derived, not assumed.** The real `PostPaymentFinancialMovementJob`/`PaymentMovementEligibilityPolicy` posting model (source-audited: `modules/Ledger/Jobs/PostPaymentFinancialMovementJob.php`) is extended — not replaced — with a `cash_settlement` branch that debits the new `SystemAccountRole::CASH_ON_HAND` instead of `PAYMENT_CLEARING`; the credit side (`CUSTOMER_FUNDS_LIABILITY`) is unchanged, since a cash sale still recognizes the same customer-funds-then-revenue-recognition shape the platform already uses for every captured payment. Recorded in ADR-0155 with the actual (not illustrative) result.
5. **Cash refund is not owned by Store Value.** A new tender-neutral `Modules\Order\Services\TenderRefundDispatchService` is the one dispatch boundary (by `tender_type`: `external_gateway`→Payment, `wallet`/`store_credit`/`gift_card`→Wallet, `cash`→POS). `StoreValueRefundService::refundOrder()` keeps its existing public entry point (its allocation math was already tender-neutral) but delegates per-tender application to the new dispatcher. **`ReturnRefundOrchestrator::finalizeRefund()` — the actual production RMA refund path — is rewired to call this tender-aware dispatch instead of calling `PaymentRefundService::refund()` directly**, a necessary fix discovered during source audit (the RMA engine's real refund call site pre-dated Phase-21's tender-allocation model and had never been wired to it).
6. **Exact mixed-tender policy for Phase-22**: Store Value + Cash, Store Value + Card, Cash-only, Card-only are supported. **Cash + Card in the same sale is explicitly deferred** (no atomic cash+gateway settlement protocol exists). Enforced in `PosSaleOrchestrator` and tested.
7. **Complete BOPIS lifecycle**: `reserved → preparing → ready_for_pickup → picked_up`, plus deterministic expiry/no-show (releases Inventory reservation, cancels the pickup fulfillment, refunds via the existing refund path if already paid). Pickup confirmation is idempotent and safe under a real-Postgres concurrency race (row-locked status transition).
8. **Cross-store returns are policy-gated, not unconditional.** `PosCrossStoreReturnPolicy` (POS-owned config, not a Phase-23 feature flag) defaults to allowing cross-store returns; when enabled, the cashier must be authorized for the receiving Store and the receiving InventorySource must belong to that Store. Refund still follows original tender allocation regardless of receiving Store.
9. **Barcode/SKU lookup is unambiguous.** Source-audited: `products` has `unique(tenant_id, sku)` but **no** uniqueness constraint on `barcode`; `product_variants` has no uniqueness constraint on `sku`/`barcode` at all beyond `unique(product_id, combination_hash)`. `BarcodeLookupService` resolves Tenant-scoped + Store-sellability-scoped candidates and **rejects as ambiguous** (rather than silently picking the first row) whenever more than one active sellable Product/Variant matches the same code within that scope.
10. **Manual discount invariants**: `0 <= discount <= eligible line amount`, enforced server-side in `CheckoutPricingOrchestrator`; no client-suppliable replacement price. Amount/reason/actor are frozen onto the historical `OrderItem`.
11. **Sale idempotency is DB-backed**, reusing the existing `OrderIdempotencyServiceInterface`/`OrderCreationService::createFromCheckout()` idempotency-key mechanism (not a new engine) — the POS idempotency key is `pos_sale:{tenant_id}:{register_session_id}:{client_sale_id}`.
12. **Receipts** freeze a minimal presentation snapshot (Store display name/address, Register code/name, cashier display name) at sale time — line/tax/discount/tender values still come from the immutable Order/tender-allocation records, never a duplicated Order blob.
13. **Acceptance wording corrected** — exchanges are deferred, not implemented; Phase-22 acceptance claims only that returns/refunds reuse the existing RMA engine.
14. Full scope implemented per §14 of the Owner Delta (see Acceptance Criteria below).

## 3. Architecture (as built)

See ADR-0154 (module boundary/no-second-core), ADR-0155 (cash Ledger posting), ADR-0156 (register/session/cash-movement model), ADR-0157 (manual discount). Key tables: `pos_registers`, `pos_register_sessions`, `pos_cash_movements`, `pos_receipts`, plus additive columns on `orders`, `cart_lines` (metadata-embedded), `checkout_sessions`, and widened `tender_type` CHECK constraints on `order_payment_tender_allocations`/`refund_tender_allocations`.

## 4. Acceptance Criteria

- [x] `modules/POS` reuses Cart/Checkout/Order/Payment/Inventory/Ledger/Customer/Returns — no second commerce-core engine.
- [x] Register resolves an exact Store→active StoreMarket→Market→Currency→Channel→InventorySource chain; one active session per register (DB-enforced).
- [x] POS Order context validation is atomic/hard-fail with Order creation.
- [x] Cash is a real, Ledger-integrated Payment tender; cash movements are append-only; expected cash is derived, never cached; `closing_count` is not a movement.
- [x] Cash refund is dispatched through a tender-neutral orchestration seam, not owned by Store Value; the real RMA refund path uses it.
- [x] Mixed-tender policy: Cash+Card explicitly unsupported and tested as such.
- [x] BOPIS lifecycle complete with idempotent, concurrency-safe pickup confirmation.
- [x] Cross-store returns are policy-gated.
- [x] Barcode/SKU lookup rejects ambiguous matches rather than guessing.
- [x] Manual discount is bounded, permission-gated, audit-logged.
- [x] Sale idempotency is DB-backed via the existing Order idempotency mechanism.
- [x] Returns/refunds reuse the existing RMA engine; **exchanges are deferred, not implemented.**
- [x] Full regression stays green; PHPStan L8 clean; Pint clean; `npm run build` clean; audits clean.
- [x] `PROJECT_MASTER_PLAN.md` untouched; Phase-23 not started.
