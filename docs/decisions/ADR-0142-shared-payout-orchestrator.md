# ADR-0142: Shared Payout Orchestrator Across Payable Beneficiary Types

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0142                              |
| Status      | Accepted                              |
| Date        | 2026-09-05                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-19                              |

## Context

Phase-19 introduces the Affiliate program, which needs the exact same request/reserve/approve/process/finalize/cancel/fail payout lifecycle Marketplace already built for Vendor payables (`Modules\Marketplace\Services\PayoutService`). An initial draft mirrored that class into a second, hand-copied `AffiliatePayoutService`. The Owner Delta explicitly rejected this: two independently-maintained copies of the same financial state machine is exactly the kind of drift that produces silent divergence (a bug fix applied to one, forgotten in the other) in code that moves money.

## Decision

The request/allocate/finalize/cancel/fail algorithm — locking the beneficiary aggregate, locking and reserving candidate payable entries in deterministic order, verifying total-reserved-equals-requested, the idempotent-finalize replay, and the settlement/disbursement-entry creation — now lives exactly once, in `App\Core\Payables\Services\AbstractPayoutOrchestrator`, extracted verbatim from the original `PayoutService`. `Modules\Marketplace\Services\PayoutService` and `Modules\Affiliate\Services\AffiliatePayoutService` are both thin adapters over it: each supplies only its own Eloquent model classes (`PayoutRequest` vs `AffiliatePayoutRequest`, etc.), its own beneficiary-eligibility check (Vendor active-status vs Affiliate active-status), and its own exception types via abstract hook methods — every public method on each adapter is a one-line delegation plus a covariant return-type narrowing, never a reimplementation.

The four purely beneficiary-agnostic state-machine enums (`PayoutRequestStatus`, `PayoutAllocationStatus`, `PayableEntryType`, `PayableAvailabilityStatus`) moved from `Modules\Marketplace\Enums` to `App\Core\Payables\Enums` for the same reason — nothing about "requested/approved/processing/paid" or "pending/available/held" is Vendor-specific, so both beneficiary types share the identical enum classes rather than each defining their own copy. Domain-owned payable *subledgers* (`VendorPayableEntry`, `AffiliatePayableEntry`) deliberately stay separate tables/models per beneficiary — only the orchestration layer above them is unified, not the economic ledgers themselves, which remain independently auditable per bounded context.

The orchestrator operates through Eloquent's `getAttribute()`/`setAttribute()` rather than magic properties, since it deliberately works across more than one concrete Model subtype and cannot statically know either one's declared properties.

## Consequences

- A future third payable beneficiary type (were one ever needed) reuses the same orchestrator with the same small hook surface — no fourth engine to write.
- Marketplace's existing test suite (`PayoutRequestAndSettlementTest`, `VendorBalanceEquationsTest`, `PostgreSqlMarketplaceConcurrencyTest`) required only import-path updates for the moved enums — zero behavioral changes, all passing unchanged.
- `AffiliatePayoutOrchestrationTest` proves the shared engine end-to-end for Affiliate, scenario-for-scenario against the same cases Marketplace's own payout test covers.
- A grep-based architecture test (`Phase19ArchitectureTest::test_only_one_payout_orchestration_algorithm_exists`) fails the build if either adapter is ever caught reimplementing a `DB::transaction` state machine of its own rather than delegating to `AbstractPayoutOrchestrator`.

## References

- `app/Core/Payables/Services/AbstractPayoutOrchestrator.php`
- `modules/Marketplace/Services/PayoutService.php`, `modules/Affiliate/Services/AffiliatePayoutService.php`
- `tests/Feature/Affiliate/AffiliatePayoutOrchestrationTest.php`
