# Subscriptions Module Specification

**Module Namespace**: `Modules\Subscriptions`
**Root Path**: `modules/Subscriptions/`
**Status**: Active Production Module (PHASE-21)

---

## 1. Overview & Architectural Boundaries

The `Subscriptions` module adds recurring-billing Subscription plans and lifecycle, reusing the existing Checkout/Order/Payment pipeline for every renewal — no second Order engine. See `docs/decisions/ADR-0148-subscription-renewal-reuses-ordinary-pipeline.md` and `ADR-0153-subscription-billing-period-serialization.md`.

### Key Invariants:

1. **Price is never duplicated**: `SubscriptionPlan` carries only billing-cadence metadata (`billing_interval`, `trial_days`); the actual price resolves through the existing `PriceResolver`/`PriceBook` exactly like any Product.
2. **Serialization precedes charging (Owner Delta §11)**: a billing period is claimed via a DB-unique `SubscriptionRenewalAttempt` row BEFORE any Cart/Order is built or provider is called.
3. **A dedicated, isolated renewal Cart** — never the customer's own live storefront cart.
4. **Exact, decided change scope (D.44)**: cancel-now, cancel-at-period-end, plan-change-at-next-renewal (no proration), payment-method replacement, grace reactivation. Pause/resume and quantity-change are explicitly deferred — not built.
5. **Recurring rejected at setup, not renewal**: a non-recurring-capable gateway fails `SubscriptionService::createSubscription()` immediately with `GatewayDoesNotSupportRecurringException`.

---

## 2. Directory Layout

```
modules/Subscriptions/
├── module.json
├── SubscriptionsServiceProvider.php
├── Enums/{SubscriptionStatus,SubscriptionRenewalStatus}.php
├── Exceptions/SubscriptionException.php
├── Models/{SubscriptionPlan,Subscription,SubscriptionRenewalAttempt}.php
├── Services/{SubscriptionService,SubscriptionRenewalService}.php
├── Console/Commands/ProcessSubscriptionRenewalsCommand.php
├── Livewire/ControlCenter/{SubscriptionPlanManager,SubscriptionManager}.php
├── Livewire/Storefront/SubscriptionsPage.php
└── Routes/web.php
```

## 3. Renewal Lifecycle

`subscriptions:process-renewals` (hourly) drives `SubscriptionRenewalService::processDueRenewals()`: for each due Subscription, claim the period (`SubscriptionRenewalAttempt` unique-constraint INSERT) → build an isolated Cart → run the existing `CheckoutOrchestrator`/`OrderCreationService` pipeline (no shipping/inventory step, since `SubscriptionProductType` requires neither) → `PaymentInitiationService::initiateOffSessionPayment()` → resolve the attempt to `succeeded`/`failed`/`unknown`, advancing `current_period_end`/`next_billing_at` only on success.

## 4. Dunning

`SubscriptionStatus`: `active → (charge fails) → past_due → (retry succeeds) → active`; `past_due → (retries exhausted) → suspended`; `suspended → (payment resolved) → active`. Retries re-claim the SAME `SubscriptionRenewalAttempt` row (an update, not a second insert) per `SubscriptionPlan.dunning_retry_days` (default `[1, 3, 7]`), never retried indefinitely.

## 5. Tests

`tests/Feature/Subscriptions/SubscriptionLifecycleTest.php`, `tests/Concurrency/PostgreSqlSubscriptionRenewalConcurrencyTest.php`.
