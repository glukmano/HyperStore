# ADR-0154: POS Reuses the Ordinary Checkout/Order/Payment Pipeline — No Second Commerce Core

## Status
Accepted — Phase-22.

## Context
`PROJECT_MASTER_PLAN.md` §20 mandates POS reuse Catalog/Pricing/Inventory/Customers/Orders/Payments/Returns/RBAC and never become a second commerce core. The source audit confirmed the existing `Cart -> CheckoutOrchestrator -> CheckoutPricingOrchestrator -> OrderCreationService -> PaymentInitiationService` pipeline already handles pricing, promotions, tax, inventory reservation, mixed tender (Phase-21), and Order snapshotting correctly for every prior commerce phase (B2B, Auctions, Booking, Subscriptions).

## Decision
`modules/POS` owns only Register, RegisterSession, CashMovement, POS sale orchestration, receipts, and manual-discount audit. A POS sale is built as an ordinary `Cart` (tagged with the POS `Channel` and the Register's resolved Store/Market context), driven through the **existing, unmodified** `CheckoutOrchestratorInterface` and `OrderCreationServiceInterface` — producing an ordinary `Order`. There is no `PosSale` model and no parallel pricing/tax/inventory engine. POS-specific facts (register/session/cashier/receipt number) are frozen onto the `Order` via additive columns and a hard-fail hook (see ADR-0156), mirroring the Affiliate/B2B non-invasive hook pattern already proven across Phase-19/20/21.

## Consequences
- Zero duplicated commerce logic; every existing Checkout/Order/Payment test and invariant applies unchanged to a POS-originated Order.
- POS inherits Phase-21's mixed-tender (`amountDueMinor`) mechanism and the existing Inventory reservation service verbatim — no separate stock-reservation code path exists, so POS and web Checkout cannot independently oversell the same unit (proven under real-Postgres concurrency).
- Any future change to Checkout/Order/Payment automatically applies to POS with no separate POS-side maintenance.
