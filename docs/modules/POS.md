# POS Module

Phase-22. Point of Sale / Omnichannel — reuses Cart/Checkout/Order/Payment/Inventory/Ledger/Customers/Returns; owns only Register, RegisterSession, CashMovement, POS sale orchestration, receipts, and manual-discount audit.

See `docs/phases/PHASE-22-POS-OMNICHANNEL.md` for the full design and Owner Delta, and ADR-0154 through ADR-0157 for the key architectural decisions.

## Key Models
- `PosRegister` — `store_market_id` (exact Store/Market context), `channel_id`, `inventory_source_id`.
- `PosRegisterSession` — one active session per register (DB-enforced), opening/closing cash snapshot.
- `PosCashMovement` — append-only, idempotent drawer movements (`opening_float`, `sale_cash_in`, `refund_cash_out`, `paid_in`, `paid_out`).
- `PosReceipt` — presentation-only, minimal frozen header; economic values always re-derived from the Order.
- `PosManualDiscountAuditLogEntry` — every manual cashier discount, permanently audit-logged.
- `PosCrossStoreReturnPolicy` — per-Tenant policy toggle (not a Phase-23 feature flag).

## Key Services
- `PosRegisterContextResolver` — resolves/validates the Tenant→Store→active StoreMarket→Market→Currency→Channel→InventorySource chain.
- `PosRegisterSessionService` — open/close, atomic opening-float movement + snapshot.
- `PosCashMovementService` — idempotent movement recording, live expected-cash derivation.
- `PosSaleOrchestrator` — drives the existing Cart/Checkout/Order/Payment pipeline for a POS sale; DB-backed idempotency.
- `PosOrderContextHook` — hard-fail Order-creation-time validation (see ADR-0156).
- `PosCashRefundService` — cash refund via drawer movement, with a Store-Credit fallback when no register is open.
- `BarcodeLookupService` — Tenant+Store-scoped, rejects ambiguous matches.
- `PosPickupService` — BOPIS lifecycle (`ready_for_pickup` → `picked_up`, no-show expiry).
- `PosCrossStoreReturnPolicyService` — enforces the cross-store return policy.

## Permissions
Seeded by `Database\Seeders\Phase22PermissionSeeder`: `pos.registers.view/manage`, `pos.register.open`, `pos.session.close`, `pos.sale.create`, `pos.discount.manual`, `pos.refund.process`, `pos.void`, `pos.cash.movement`, `pos.manager.override`.
