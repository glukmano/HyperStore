# ADR-0132: Storefront Core — Thin Composition Boundary Over Existing Services

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0132                              |
| Status      | Accepted                              |
| Date        | 2026-09-04                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-15                              |

## Context

Cart and Checkout service layers (Phase-07) and Order creation (Phase-08) have been complete and fully tested since their respective phases, but no public storefront route or view existed anywhere in the codebase prior to Phase-15 — Cart and Checkout modules had empty `Routes/web.php` files and no `Livewire/` directory at all. Building the first Storefront Core risks re-implementing checkout/pricing/inventory-reservation logic inside presentation code if not explicitly bounded.

## Decision

- `App\Livewire\Storefront\*` components (`Home`, `CategoryPage`, `ProductPage`, `CartPage`, `CheckoutPage`, `OrderConfirmationPage`, `OrderLookupPage`, `VendorStorefrontPage`) are Core-owned (not Module-owned, matching the existing `App\Livewire\ControlCenter\DashboardOverview` precedent) and act as thin composition/view layers only.
- Every mutating or business-rule-bearing operation is delegated to an existing, already-tested Module service interface: `Modules\Cart\Contracts\CartServiceInterface`, `Modules\Checkout\Contracts\CheckoutOrchestratorInterface`, `Modules\Order\Contracts\OrderCreationServiceInterface`, `Modules\Pricing\Contracts\PriceResolverInterface`, `Modules\Marketplace\Contracts\VendorStorefrontResolverInterface`. No Storefront component reimplements cart line pricing, checkout state transitions, inventory reservation, or order-number generation.
- Storefront context (Tenant/Store/Market/Channel/Currency) is resolved once, at the HTTP boundary, by `ResolveStorefrontThemeMiddleware` using the existing `App\Core\Routing\DomainAddressingService::findStoreByHost()` and written into the request-scoped `ContextManager` — Storefront components read context, they do not re-resolve it.
- Checkout payment capture is explicitly scoped out of Phase-15: the review step surfaces a "payment is finalized after order confirmation" notice rather than wiring an unverified Payment-module integration, to avoid fabricating gateway behavior without an audited contract. This is recorded as an open item for the phase that formally exposes a storefront-facing Payment capture contract.

## Consequences

- The storefront can be safely extended (new pages, new Product Type sections) without ever needing to touch a Module's Service layer.
- Checkout's real business invariants (conservation, idempotency, reservation-vs-availability) — already proven by Phase-07/08/14's own test suites — are inherited for free by the storefront, not re-derived.
- Payment capture UI remains a known, explicitly flagged gap rather than a silently incorrect integration.

## References

- PROJECT_MASTER_PLAN.md §22 (Theme System), §13 (Orders/Fulfillment/Dropshipping)
- `docs/phases/PHASE-15-CONTROL-CENTER-STOREFRONT-THEME-SYSTEM.md`
