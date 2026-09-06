# ADR-0155: Cash as a Ledger-Integrated Payment Tender

## Status
Accepted — Phase-22.

## Context
Owner Delta §4 required the real Phase-09/Phase-21 Payment→Ledger posting model to be source-audited before designing cash's Ledger integration — never assuming a counterpart account merely to balance the journal. Source audit of `modules/Ledger/Jobs/PostPaymentFinancialMovementJob.php` and `modules/Ledger/Policies/PaymentMovementEligibilityPolicy.php` found: every captured Payment posts a balanced `JournalEntry` — debit `SystemAccountRole::PAYMENT_CLEARING`, credit `SystemAccountRole::CUSTOMER_FUNDS_LIABILITY` — via one job, selected by `PaymentMovementEligibilityPolicy::resolvePostingType()` mapping `operationType` to `capture`/`refund`. A refund reverses the same two accounts.

## Decision
Cash is modeled as a genuine Payment tender (`PaymentOperationType::CASH_SETTLEMENT`), never a faked/skipped payment. `PaymentInitiationService::initiateCashPayment()` mirrors the existing `handleZeroTotalOrder()` "no gateway call, immediate success" shape, but for a real positive amount. `PaymentMovementEligibilityPolicy` is extended (additively) to treat `cash_settlement`/`cash_refund` as eligible, mapping to the same `capture`/`refund` posting types. `PostPaymentFinancialMovementJob` is extended with the **smallest possible branch**: when the operation is a cash settlement/refund, the debit/credit account that would otherwise be `PAYMENT_CLEARING` becomes the new `SystemAccountRole::CASH_ON_HAND` (asset, debit-normal) instead — the `CUSTOMER_FUNDS_LIABILITY` side is completely unchanged. No second Ledger posting path, no second job, no assumed counterpart account beyond this one swap.

`LedgerAccountRegistry::ensureRequiredSystemAccounts()` provisions `CASH_ON_HAND` per Tenant exactly like every other system account (fail-closed resolution, no implicit provisioning during posting).

The `PosCashMovement` operational record (drawer accounting, see ADR-0156) and this Ledger posting share one durable source identity: the `PaymentTransaction.uuid` for the settlement, and `order_refund`/`refund_event_uuid` for a cash refund — both idempotent, independently, by their own established conventions.

## Consequences
- Cash sales/refunds appear correctly in the platform's real accounting model (a genuine asset movement, not a liability-only entry).
- The existing capture/refund posting job's shape, immutability guarantees, and idempotency are entirely unchanged for every non-cash Payment.
- A future real card-present or other tender type follows the identical pattern: a new `SystemAccountRole` (if the tender is a genuinely new asset/liability), never a new job.
