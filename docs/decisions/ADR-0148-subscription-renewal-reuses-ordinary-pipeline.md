# ADR-0148: Subscription Renewal Reuses the Ordinary Checkout/Order/Payment Pipeline

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0148                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-21                              |

## Context

An unattended, scheduled Subscription renewal must produce a real, historically-correct Order without inventing a second Order-creation code path — the platform's explicit, repeated architectural rule across every phase of this program.

## Decision

`SubscriptionRenewalService::executeClaimedRenewal()` drives the **existing** pipeline programmatically: (1) creates a dedicated, isolated `Cart` directly (never via `CartService::getOrCreateActiveCart()`, which would otherwise merge the synthetic renewal purchase into whatever the customer happens to be live-shopping for at that moment), (2) `CartService::addLine()` adds the plan's Product, (3) `CheckoutOrchestratorInterface::createFromCart()` → `setCustomerData()` → `markReadyForOrder()` — the exact same state-machine transitions a human checkout uses, (4) `OrderCreationServiceInterface::createFromCheckout()` produces an ordinary `Order`. `SubscriptionProductType::supportsInventory()` was corrected to `false` (a subscription is a recurring service/access grant, not a stock-tracked physical good) so a renewal never blocks on an Inventory reservation step; `requiresShipping()` already defaulted to `false`. Together this means a renewal reaches `ready_for_order` via `customer_info_ready → ready_for_order` directly, with no interactive address/shipping/inventory step — exactly matching D.42's "no interactive steps" requirement without any special-casing inside Checkout itself.

Payment is charged via a new, narrowly-scoped `PaymentInitiationService::initiateOffSessionPayment()` method (ADR-0149) — not a second payment-initiation service, an additional method on the existing one, mirroring `executeInitiation()`'s own pre-call/gateway-call/post-call transactional shape.

## Consequences

- Plan price changes flow through the ordinary `PriceResolver`/`PriceBook` exactly like any Product purchase (ADR C.28) — the CURRENT resolved price is what a renewal charges, frozen onto the new renewal Order's `OrderItem.plan_snapshot` at creation time; a later price change never retroactively alters that already-created historical Order (proven by `SubscriptionLifecycleTest::test_plan_price_change_never_alters_an_already_frozen_historical_renewal_snapshot`).
- No `AuctionOrder`/`SubscriptionOrder`/`RenewalOrder` model exists — every renewal is a plain `Order`, queryable and reportable identically to any storefront purchase.
- `SubscriptionRenewalAttempt.order_id` is the durable link from a specific billing-period claim to the Order it produced (ADR-0153).

## References

- `modules/Subscriptions/Services/SubscriptionRenewalService.php`
- `modules/Catalog/ProductTypes/SubscriptionProductType.php`
- `tests/Feature/Subscriptions/SubscriptionLifecycleTest.php`, `tests/Concurrency/PostgreSqlSubscriptionRenewalConcurrencyTest.php`
