# B2B Module Specification

**Module Namespace**: `Modules\B2B`
**Root Path**: `modules/B2B/`
**Status**: Active Production Module (PHASE-20)

---

## 1. Overview & Architectural Boundaries

The `B2B` module adds Company (wholesale/organization) accounts, Company-scoped purchasing roles, negotiated Quote/RFQ pricing, and Company credit-terms purchasing — all integrating through the existing Catalog/Pricing/Checkout/Order pipeline. See `docs/decisions/ADR-0144-b2b-company-credit-append-only-subledger.md` for the credit-exposure model's full rationale.

### Key Invariants:

1. **One source of truth for Company membership**: `CompanyUser` is the sole record of which Company a User belongs to. `CustomerProfile` is never modified by this module and carries no `company_id`.
2. **Company-scoped, never global, roles**: `CompanyAuthorizationService::assertRole()` always resolves a User's role from their own `CompanyUser` row scoped to the *specific target Company*. `spatie/laravel-permission` is reserved exclusively for platform/Control-Center staff RBAC (`b2b.companies.*`, `b2b.quotes.*`) — never for a Company's internal `owner`/`buyer`/`approver` roles.
3. **Server-authoritative Quote pricing**: a negotiated price is never client-suppliable. `QuoteLinePriceResolverInterface::resolveNegotiatedPricesForCart()` re-validates `Quote.status === Accepted` on every call and resolves prices only from the accepted `QuoteLine` rows.
4. **Append-only credit subledger**: `CompanyCreditEntry` has no mutable balance column; every release/settlement references its reservation via `reverses_entry_id`, backstopped by a Postgres partial unique index.
5. **No coupon/Loyalty stacking on Quote checkouts by default**: a Cart/CheckoutSession carrying an accepted `quote_id` rejects `applyCoupon()`.

---

## 2. Directory Layout

```
modules/B2B/
├── module.json
├── B2BServiceProvider.php
├── Enums/
│   ├── CompanyStatus.php
│   ├── CompanyUserRole.php
│   ├── CompanyCreditEntryType.php
│   ├── QuoteStatus.php
│   └── CompanyInvoiceStatus.php
├── Exceptions/
│   ├── B2BException.php
│   ├── InsufficientCompanyCreditException.php
│   ├── CompanyAuthorizationException.php
│   ├── QuoteNotAcceptableException.php
│   └── QuoteCheckoutCouponNotAllowedException.php
├── Models/
│   ├── Company.php
│   ├── CompanyUser.php
│   ├── CompanyCreditAccount.php
│   ├── CompanyCreditAccountLock.php
│   ├── CompanyCreditEntry.php
│   ├── CompanyInvoice.php
│   ├── Quote.php
│   └── QuoteLine.php
├── Contracts/
│   ├── CompanyCreditServiceInterface.php
│   ├── CustomerGroupResolverInterface.php
│   ├── QuoteLinePriceResolverInterface.php
│   └── CompanyOrderCreditHookInterface.php
├── Services/
│   ├── CompanyCreditService.php
│   ├── CompanyAuthorizationService.php
│   ├── QuoteService.php
│   ├── CustomerGroupResolver.php
│   ├── QuoteLinePriceResolver.php
│   └── CompanyOrderCreditHook.php
├── Livewire/
│   ├── ControlCenter/{CompanyManager,QuoteManager,CompanyCreditManager}.php
│   └── Storefront/{CompanyAccountPage,QuoteRequestPage}.php
├── Resources/views/livewire/{control-center,storefront}/
└── Routes/web.php
```

## 3. Integration Points

- **Pricing**: `CustomerGroupResolverInterface::resolveForUser()` resolves an active Company's `customer_group_id` into `CheckoutPricingOrchestrator`'s `PricingContext`/`PromotionContext` (previously hardcoded `null`) — wiring already-existing `PriceBook`/`TierPrice` wholesale pricing through Checkout for the first time.
- **Checkout**: `CheckoutPricingOrchestrator::calculate()` substitutes `QuoteLine.negotiated_unit_price_minor` for cart lines tracing back to an accepted Quote, applied after `PriceResolver::resolve()`, before promotion evaluation.
- **Cart**: `cart_lines.quote_line_id` (nullable, server-set only) provides per-line Quote provenance; `QuoteService::acceptAndBuildCart()` forces distinct CartLine signatures per QuoteLine via `customizations['quote_line_id']` so two QuoteLines for the same product/variant never silently merge.
- **Order**: `CompanyOrderCreditHookInterface::applyCompanyContextAndReserveCredit()` runs inside `OrderCreationService`'s transaction immediately after `Order::create()` — an `InsufficientCompanyCreditException` here is not caught and rolls back the whole Order. A Company on payment terms gets `Order.payment_status = 'invoiced'` and a `CompanyInvoice` row instead of an immediate `PaymentInitiationService` call.

## 4. Company Credit Lifecycle

`reservation` (Order creation, idempotent by `order.uuid`) → `release` (Order cancelled) **or** `settlement` (invoice paid), each referencing the reservation's `id` via `reverses_entry_id`; enforced exactly-once by a Postgres partial unique index. `available_credit = max(0, approved_limit_minor − SUM(amount_minor))`, always derived. See ADR-0144.

## 5. Tests

`tests/Feature/B2B/{CompanyCreditServiceTest,QuoteCheckoutIntegrationTest}.php`, `tests/Concurrency/PostgreSqlCompanyCreditConcurrencyTest.php`.
