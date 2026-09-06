# ADR-0151: Mixed Tender Checkout — amountDueMinor Extension with Hold/Capture Semantics, Not a Coupon

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0151                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-21                              |

## Context

Phase-19's Loyalty redemption applies its discount by minting a single-use Coupon, reusing the existing `applyCoupon()` pipeline. Store Value cannot reuse this trick: `Cart.coupon_code` is a single scalar slot already occupied by Loyalty, and — more fundamentally — a Coupon is a *merchandise discount* that reduces the taxable base, whereas Store Value is conventionally applied *after* tax, against the amount actually tendered, exactly like a gift card at a real point of sale.

## Decision

`CheckoutTotals` gains two new fields, both defaulting to a no-op for every existing call site: `storeValueApplied` (defaults to zero) and `amountDue` (`grandTotal − storeValueApplied`, defaults to equal to `grandTotal`). The existing reconciliation assertion is untouched — `grandTotal` continues to represent the full commercial value of the Order, computed exactly as before Phase-21. `CheckoutPricingOrchestrator::calculate()` gained a fourth, optional parameter (`storeValueAppliedMinor = 0`) threaded through from `CheckoutSession.store_value_applied_minor` at every one of `CheckoutOrchestrator`'s six call sites.

Applying Store Value is a direct `StoreValueServiceInterface::placeHold()` call (via `CheckoutOrchestrator::applyStoreValue()` → `Modules\Wallet\Services\StoreValueCheckoutHook`) — no Promotion/Coupon proxy object is minted at all. `PaymentInitiationService`'s amount invariant is relaxed from `grand_total_minor` to `amount_due_minor ?? grand_total_minor` (one line, zero behavior change for every non-Store-Value Order). The pre-existing "zero-total order" branch (`handleZeroTotalOrder()`) already handled a `$0` charge correctly and needed no modification — a fully-Store-Value-covered Order simply routes through it naturally once `amountMinor === 0`.

## Consequences

- `OrderSnapshotValidator`'s closed-array-shape totals validator (the confirmed-recurring ADR-0143 gap) required widening for exactly two new optional keys (`store_value_applied_minor`, `amount_due_minor`) — the narrowest possible fix, not a broader shape change.
- `orders.amount_due_minor` is a new, nullable, additive column — `NULL` (interpreted as "equal to grand_total_minor") for every historical Order, explicit for every new one.
- At most one Store Value hold per instrument type is combined into one checkout (D.49) — never an arbitrary stack of several accounts of the same type — keeping the mixed-tender reconciliation simple and the refund-allocation matrix (ADR/D.50) tractable.

## References

- `modules/Checkout/DTOs/CheckoutTotals.php`, `modules/Checkout/Services/{CheckoutPricingOrchestrator,CheckoutOrchestrator}.php`
- `modules/Payment/Services/PaymentInitiationService.php`
- `modules/Order/Services/OrderSnapshotValidator.php`
- `tests/Feature/Checkout/MixedTenderCheckoutTest.php`
