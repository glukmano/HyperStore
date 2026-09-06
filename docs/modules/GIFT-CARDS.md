# Gift Cards Module Specification

**Module Namespace**: `Modules\GiftCards`
**Root Path**: `modules/GiftCards/`
**Status**: Active Production Module (PHASE-21)

---

## 1. Overview & Architectural Boundaries

The `GiftCards` module is thin — it delegates all balance/ledger movement to `modules/Wallet`'s `StoreValueService`, per the explicit design to avoid a third duplicated money engine. See `docs/decisions/ADR-0150-store-value-ledger-integration.md`.

### Key Invariants:

1. **The plaintext code is never persisted** — generated once, shown/emailed to the purchaser at issuance, then only `code_hash` (SHA-256) and `code_last4` (operator-safe display) are stored.
2. **One Gift Card, one dedicated `StoreValueAccount`, forever** — never merged into another Gift Card's balance or a Customer's Wallet/Store Credit account, even after the Customer "claims" it (claiming only sets `customer_profile_id` on the SAME account row).
3. **Partial redemption is native** — because the balance lives in the Gift Card's own `StoreValueEntry` ledger, multiple spends across several Orders are just ordinary `hold`→`capture` pairs against that one account.
4. **Buying a Gift Card never earns Loyalty or Affiliate commission** (C.31) — deferred spending, not a commissionable purchase; double-incentive risk.
5. **Rate-limited redemption** — Laravel's built-in `RateLimiter`, no new package, mitigating brute-force code-guessing alongside the code's own high entropy.

---

## 2. Directory Layout

```
modules/GiftCards/
├── module.json
├── GiftCardsServiceProvider.php
├── Exceptions/{GiftCardException,GiftCardNotFoundException,GiftCardAlreadyRedeemedException}.php
├── Models/GiftCard.php
├── Services/GiftCardService.php
├── Notifications/GiftCardIssuedNotification.php
├── Listeners/IssueGiftCardOnPaymentCaptured.php
├── Livewire/ControlCenter/GiftCardManager.php
├── Livewire/Storefront/RedeemGiftCardWidget.php
└── Routes/web.php
```

## 3. Issuance & Redemption

`GiftCardService::issue()` generates a high-entropy code, creates the `GiftCard` row (`status='unactivated'`), then — inside one transaction — creates a dedicated `StoreValueAccount` (`instrument_type='gift_card'`, `customer_profile_id=null`) and calls `StoreValueService::issue()` to credit it, before flipping the card to `active`. A self-purchased Gift Card (via the ordinary Checkout pipeline, `product_type='gift-card'`) is issued by `IssueGiftCardOnPaymentCaptured` (a `PaymentCaptured` listener, matching the "grant only on payment success" discipline used throughout Phase-21) and the code is emailed via `GiftCardIssuedNotification` — never persisted anywhere after that send. `GiftCardService::resolveAccountForCode()` looks up by `hash(code)`, optionally claims the account for a redeeming Customer, and rejects an unknown or already-redeemed/deactivated code.

## 4. Tests

`tests/Feature/GiftCards/GiftCardServiceTest.php` (includes a plaintext-never-persisted assertion), `tests/Concurrency/PostgreSqlStoreValueConcurrencyTest.php::test_gift_card_cannot_double_spend_under_concurrent_holds`.
