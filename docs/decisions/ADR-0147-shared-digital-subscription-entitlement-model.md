# ADR-0147: Shared Digital/Subscription Entitlement Model — One Table, Domain-Specific Policies

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0147                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-21                              |

## Context

Both Digital downloads and Subscription access reduce to the same three access-control questions: is there a granted, non-revoked, non-expired/non-exhausted permission for this Customer against this specific purchase source? Building two parallel permission tables risked drift between two access-check implementations for what is structurally the same check.

## Decision

`Modules\DigitalDelivery\Models\CustomerEntitlement` is the ONE shared table for both `entitlement_type` values (`digital_download`, `subscription_access`), unique on `(source_type, source_uuid)`: `granted_at`, `revoked_at`, `expires_at`, `max_uses`, `used_count`. `isAccessible()` is a single method (not-revoked AND not-expired AND under-max-uses) used identically by both domains. It is explicitly distinct from `OrderItem` — the immutable historical purchase record — since Subscription renewal *updates* this row's `expires_at` on each successful period, while `OrderItem`/renewal-Order rows accumulate one-per-period, immutably, alongside it.

`modules/Subscriptions` references this table directly (a legitimate shared-kernel dependency, matching how every other Phase-20/21 module references core Checkout/Order models directly) rather than through a peer-module contract, since `CustomerEntitlement` is the shared primitive both domains were built around from the start — not a service one domain owns and the other calls into.

## Consequences

- A single `checkAccess()`/`isAccessible()` code path is tested once (D.37's five negative cases) and trusted by both domains — no risk of the two access checks silently diverging.
- Digital-specific behavior (download-count exhaustion via `max_uses`) and Subscription-specific behavior (period-based `expires_at` refreshed on renewal) are both expressed through the SAME two nullable columns, applied differently by each domain's own service (`DigitalAssetDeliveryService` vs. `SubscriptionRenewalService`) — no per-type subclassing or polymorphic table needed at this scale.
- `source_type`/`source_uuid` is `order_item` for Digital/License (source_uuid = the OrderItem's own id) and would be `subscription` for Subscription access if a Subscription-access entitlement row is ever created explicitly (Phase-21 as shipped grants Digital/License entitlements this way; Subscription access itself is currently gated by `Subscription.status`, not a separate `CustomerEntitlement` row — the `subscription_access` enum case is reserved for the shared model's completeness and future use, e.g. gating third-party content access by entitlement rather than by directly querying Subscription state).

## References

- `modules/DigitalDelivery/Models/CustomerEntitlement.php`, `modules/DigitalDelivery/Enums/EntitlementType.php`
- `modules/DigitalDelivery/Services/{CustomerEntitlementService,DigitalAssetDeliveryService}.php`
- `tests/Feature/DigitalDelivery/DigitalEntitlementAndDeliveryTest.php`
