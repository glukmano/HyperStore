# ADR-0149: Recurring Payment Capability as a Backwards-Compatible Interface Extension

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0149                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-21                              |

## Context

Subscriptions require charging a previously-tokenized payment method off-session, on a schedule, with no interactive card-entry step. The existing `PaymentGatewayInterface` (`purchase`/`authorize`/`capture`/`refund`/`void`) has no concept of tokenization or off-session charging, and every existing/Plugin-SDK gateway implementation must keep compiling and working unchanged.

## Decision

`Modules\Payment\Contracts\RecurringPaymentGatewayInterface extends PaymentGatewayInterface` is a new, **optional** capability interface — the base interface is never modified. It adds exactly two methods: `setupPaymentMethod(SetupPaymentMethodRequest): SetupPaymentMethodResult` (attaches/tokenizes a reusable payment method, returning an opaque `provider_reference` — never raw card data) and `chargeOffSession(GatewayOffSessionChargeRequest): GatewayPaymentResult` (charges that reference later, accepting the CALLER's own `provider_idempotency_key`, computed at billing-period claim time per ADR-0153 — never generated inside the gateway call itself).

A caller detects recurring capability via `$gateway instanceof RecurringPaymentGatewayInterface`. A gateway that does not implement it is simply, correctly, "not recurring-capable" — `SubscriptionService::createSubscription()` and `replacePaymentMethod()` both check this and throw `Modules\Payment\Exceptions\GatewayDoesNotSupportRecurringException` immediately at setup time, never silently attempted and failed later at renewal time. `Modules\Payment\Models\CustomerPaymentMethod` stores only `gateway_provider_code` + `gateway_reference` (the opaque token) + non-sensitive display fields (`display_brand`, `display_last4`) — never a raw card number, CVC, or expiry.

`FakePaymentGateway` (the only concrete gateway that exists in this codebase — no real Stripe/PayPal adapter exists) gained a real, test-only implementation of both new methods, proving the interface shape is usable without depending on external network calls.

`PaymentInitiationService::initiateOffSessionPayment()` is a new, additive method mirroring `executeInitiation()`'s exact pre-call/gateway-call/post-call transactional shape, but calling `chargeOffSession()` instead of `purchase()`/`authorize()` and accepting the pre-computed idempotency key rather than generating one.

## Consequences

- Every existing Payment test, every existing gateway registration, and the entire pre-Phase-21 Payment module is untouched and unaffected — proven by the full regression suite staying green.
- A real third-party gateway adapter (Stripe, PayPal, etc.) is out of scope for this phase (none exists today) — the SPI is deliberately conservative and capability-flag-gated specifically so it does not over-fit to `FakePaymentGateway`'s convenience.

## References

- `modules/Payment/Contracts/RecurringPaymentGatewayInterface.php`
- `modules/Payment/DTOs/{SetupPaymentMethodRequest,SetupPaymentMethodResult,GatewayOffSessionChargeRequest}.php`
- `modules/Payment/Models/CustomerPaymentMethod.php`, `modules/Payment/Providers/FakePaymentGateway.php`
- `modules/Payment/Services/PaymentInitiationService.php` (`initiateOffSessionPayment()`)
- `tests/Feature/Subscriptions/SubscriptionLifecycleTest.php::test_subscription_creation_is_rejected_for_a_gateway_that_does_not_support_recurring`
