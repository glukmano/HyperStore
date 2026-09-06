# ADR-0157: Manual Cashier Discount — Server-Authoritative, Audit-Logged, Never a Client-Suppliable Price

## Status
Accepted — Phase-22.

## Context
No existing mechanism allows a cashier to apply a discretionary discount at the point of sale. Owner Delta §10 required `0 <= discount <= eligible line amount`, permission-gating, and full audit logging — never a client-controlled replacement price. Source audit of `CheckoutPricingOrchestrator::calculate()` found an existing, always-zero `$perLineLineDiscounts` bucket (`// Line-level discounts if any`) that `CheckoutTotals.lineDiscounts` never actually reads from — i.e., a real, already-reserved seam that had simply never been wired to anything.

## Decision
A cashier with `pos.discount.manual` applies a discount via `PosManualDiscountService::apply()`, which bounds the amount (`0 <= amount <= line subtotal`), writes it into the originating `CartLine.metadata` (`pos_manual_discount_minor`/`reason`/`applied_by_user_id`), and records a `PosManualDiscountAuditLogEntry` row in the same transaction. `CheckoutPricingOrchestrator::calculate()` reads this metadata directly per line, applies the identical bound **again** authoritatively (defense in depth — the service-level bound is UX-level, this is the real enforcement), and folds it into the previously-dead `$perLineLineDiscounts` bucket — which was already summed into `CheckoutTotals.lineDiscounts` and the grand-total reconciliation formula, requiring zero other changes to that method. The discount amount/reason/actor are frozen onto the historical `OrderItem` exactly like every other per-line snapshot field (auction/booking), read directly from the originating `CartLine`'s metadata at Order-creation time — bypassing `OrderSnapshotValidator`'s known closed-array-shape gap entirely, since that gap only affects the separately-validated `$validatedSnapshot['lines']` array, not this metadata-sourced path.

## Consequences
- No client request path can ever supply an arbitrary replacement price — only a bounded discount, checked twice (service + pricing orchestrator).
- Every manual discount is permanently auditable (`pos_manual_discount_audit_log` + the frozen `OrderItem` fields).
- The fix activates a previously dead, already-reserved pricing seam rather than adding a new one — the smallest possible correct change.
