# Wallet Module Specification

**Module Namespace**: `Modules\Wallet`
**Root Path**: `modules/Wallet/`
**Status**: Active Production Module (PHASE-21)

---

## 1. Overview & Architectural Boundaries

The `Wallet` module is the shared Store Value engine backing Wallet, Store Credit, and (via `modules/GiftCards`) Gift Cards — hold→capture/release Checkout lifecycle, Ledger-integrated. See `docs/decisions/ADR-0150-store-value-ledger-integration.md`, `ADR-0151-mixed-tender-checkout-amount-due.md`, `ADR-0152-wallet-closed-loop-non-withdrawable.md`.

### Key Invariants:

1. **Three distinct instrument types** (`wallet`/`store_credit`/`gift_card`), never merged — a Gift Card's `StoreValueAccount` is a permanent one-to-one pair with its `GiftCard` row.
2. **hold→capture/release, never an immediate final spend** (Owner Delta §16): applying Store Value at Checkout is a `hold`; Order/payment success converts it to `capture`; cancellation/failure converts it to `release`. Only `capture` (and `issue`/`refund_credit`/`expire`/`manual_adjustment_*`) post to Ledger.
3. **Exactly-once hold resolution** (Owner Delta §16, mirrors ADR-0144's B2B discipline): a hold is resolved by either `capture` OR `release`, never both — enforced by a Postgres partial unique index on `reverses_entry_id`.
4. **Atomic domain + Ledger posting** (Owner Delta §17): one `StoreValueService` method performs both the `StoreValueEntry` write and the `LedgerPostingServiceInterface::post()` call inside one transaction, sharing one idempotency identity.
5. **No implicit FX**: a currency mismatch between an account and a requested operation is a hard rejection (`StoreValueCurrencyMismatchException`).

---

## 2. Directory Layout

```
modules/Wallet/
├── module.json
├── WalletServiceProvider.php
├── Enums/{StoreValueInstrumentType,StoreValueEntryType}.php
├── Exceptions/{WalletException,InsufficientStoreValueBalanceException,StoreValueCurrencyMismatchException,StoreValueResolutionException}.php
├── Models/{StoreValueAccount,StoreValueAccountLock,StoreValueEntry}.php
├── Contracts/{StoreValueServiceInterface,StoreValueCheckoutHookInterface}.php
├── Services/
│   ├── StoreValueService.php
│   ├── StoreValueLedgerPostingService.php
│   ├── StoreValueCheckoutHook.php
│   └── StoreValueRefundService.php
├── Listeners/ConvertStoreValueHoldOnPaymentCaptured.php
├── Livewire/ControlCenter/StoreValueAccountManager.php
├── Livewire/Storefront/{WalletPage,StoreValueApplyWidget}.php
└── Routes/web.php
```

## 3. Checkout Integration

`CheckoutOrchestrator::applyStoreValue()`/`removeStoreValue()` (guarded by `app()->bound(StoreValueCheckoutHookInterface::class)`) cap the requested amount against the Order's real remaining `amountDue`, then call `StoreValueCheckoutHook::applyToCheckout()` which places a `hold` and updates `checkout_sessions.store_value_applied_minor`/`store_value_hold_refs`. `ConvertStoreValueHoldOnPaymentCaptured` (a `PaymentCaptured` listener) converts every still-held entry for the Order's originating session into a `capture` and records `order_payment_tender_allocations` for both the Store Value and external-gateway portions.

## 4. Refunds

`StoreValueRefundService::refundOrder()` — D.50's deterministic, integer-minor-unit, largest-remainder proportional allocation across the Order's original `order_payment_tender_allocations` (reusing the exact algorithm already proven in `CheckoutPricingOrchestrator`'s cart-discount allocation), snapshotted into `refund_tender_allocations` keyed by `refund_event_uuid` for idempotent retry — never blindly refunding everything to Store Credit.

## 5. Tests

`tests/Feature/Wallet/StoreValueServiceTest.php`, `tests/Feature/Checkout/MixedTenderCheckoutTest.php`, `tests/Concurrency/PostgreSqlStoreValueConcurrencyTest.php`.
