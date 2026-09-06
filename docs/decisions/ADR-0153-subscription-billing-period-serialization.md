# ADR-0153: Subscription Billing-Period Serialization Precedes Charging

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0153                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-21                              |

## Context

An unattended, scheduled renewal process can run concurrently across multiple worker processes (overlapping cron invocations, a retried job, a second scheduler instance) — without a serialization boundary established BEFORE any charge attempt, two workers could both build a Cart, both create an Order, and both charge the customer for the same billing period.

## Decision

`Modules\Subscriptions\Models\SubscriptionRenewalAttempt` is itself the PostgreSQL-authoritative claim mechanism, not merely an append-only log written after the fact. It carries a hard `unique(subscription_id, billing_period_start)` constraint, and the very first action `SubscriptionRenewalService::claimAndProcess()` takes is an `INSERT` attempting to create this row with `status='claimed'` — **before** building a Cart, before calling the provider, before anything else. If the insert conflicts (caught via `QueryException` matching the constraint name), this worker is not the owner of this billing period and returns immediately. Exactly one worker process ever becomes the owner for a given `(subscription_id, billing_period_start)`.

The `provider_idempotency_key` passed to `RecurringPaymentGatewayInterface::chargeOffSession()` is derived deterministically at claim time (`subscription:{id}:period:{billing_period_start}`) and persisted on the claimed row — so even if the HTTP call to the provider itself is retried (a network blip after the provider already processed the charge), the provider's own idempotency layer prevents a duplicate charge, independent of our own database-level claim.

A provider timeout is recorded as an explicit `unknown` status (`SubscriptionRenewalStatus::Unknown`) — never inferred as success or failure, and never automatically retried as if it were a fresh attempt merely because the outcome was uncertain. `PaymentReconciliationPendingException` (already used by the ordinary Payment reconciliation path) triggers this branch.

Dunning retries (D.43) re-use the SAME already-claimed row (an `UPDATE` back to `status='claimed'` with a fresh, attempt-numbered idempotency key) rather than inserting a second row for the same period — the unique constraint is about ownership of the period, not about counting attempts; a period is claimed once and its resolution may be revisited by the SAME claim until the plan's finite `dunning_retry_days` list is exhausted, at which point the Subscription suspends.

## Consequences

- Verified by a real two-process PostgreSQL concurrency test: both workers call `processDueRenewals()` for the same Tenant simultaneously; exactly one `SubscriptionRenewalAttempt` row exists afterward, with status `succeeded`, and exactly one `Order` was created.
- A genuine implementation bug was caught during this work: without the fix, a would-be "retry" design that inserted a NEW attempt row per retry would have violated the same unique constraint it relies on for correctness — the re-claim-the-same-row design resolves this cleanly.

## References

- `modules/Subscriptions/Models/SubscriptionRenewalAttempt.php`
- `modules/Subscriptions/Services/SubscriptionRenewalService.php`
- `database/migrations/2026_09_08_000120_create_subscriptions_tables.php` (`uq_subscription_renewal_period`)
- `tests/Concurrency/PostgreSqlSubscriptionRenewalConcurrencyTest.php`
