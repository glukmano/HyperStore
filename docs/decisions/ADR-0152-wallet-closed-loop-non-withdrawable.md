# ADR-0152: Wallet Is Closed-Loop, Non-Withdrawable in This Phase

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0152                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-21                              |

## Context

`PROJECT_MASTER_PLAN.md` mentions "wallet" only in customer-facing/audit-ledger contexts — never "withdrawal" or "cash-out." Allowing a Customer to withdraw Wallet balance to a real bank account or card would take on a materially different regulatory posture (money-transmission licensing considerations in many jurisdictions) that is deliberately out of scope absent an explicit requirement.

## Decision

`modules/Wallet` ships as closed-loop, platform/store-spendable value only, in this phase. `StoreValueService` exposes no withdrawal or cash-out operation — a Wallet balance can only be spent at checkout (`hold`/`capture`), refunded back into itself (`refund_credit`), manually adjusted by Control Center staff (`manual_adjustment_*`), or expired (`expire`). There is no code path anywhere that converts a Wallet balance into an external payout.

## Consequences

- If cash withdrawal is ever required, it is a materially new feature requiring its own explicit owner authorization, regulatory review, and likely a distinct compliance posture (KYC on withdrawal, AML monitoring) — not a natural extension of the existing hold/capture engine.
- Wallet, Store Credit, and Gift Card share one engine (`modules/Wallet`) specifically because none of them are cash-withdrawable — if withdrawal is later added for Wallet specifically, it may justify separating Wallet's `StoreValueAccount` handling from Store Credit/Gift Card's, since the three would then diverge in regulatory treatment beyond just their `SystemAccountRole` (already three distinct roles per ADR-0150).

## References

- `modules/Wallet/Contracts/StoreValueServiceInterface.php` (no withdrawal method exists)
- `docs/decisions/ADR-0150-store-value-ledger-integration.md`
