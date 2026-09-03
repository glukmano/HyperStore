# ADR-0109: Vendor Plan Subscription Entitlement & Policy-Gated Auto Approval

## Status
ACCEPTED

## Date
2026-09-03

## Context
Section 11 of `PROJECT_MASTER_PLAN.md` states: "Free Vendor Plan -> manual admin approval; Paid Monthly Plan -> automatic approval." However, a vendor assigned to a paid plan is not necessarily a paid vendor. Treating a vendor as paid merely because `monthly_fee_minor > 0` allows unproven payment states to bypass admin approval.

## Decision
1. **Authoritative Subscription Entitlement**: `vendor_plan_subscriptions` tracks authoritative billing lifecycle (`pending`, `active`, `past_due`, `cancelled`, `expired`) with mandatory `activation_source` provenance (`billing_event`, `manual_admin_approval`, `test_fake`).
2. **Policy-Gated Auto-Approval**:
   - Free plans unconditionally require manual admin approval; auto-approval returns `false`.
   - Paid plans permit auto-approval ONLY IF an authoritative subscription entitlement exists with `status = 'active'` and validated provenance.
3. **Environment Security for Test Fakes**: `activation_source = 'test_fake'` is strictly permitted only in `local` and `testing` environments. In `production` and `staging`, `test_fake` fails closed.

## Consequences
- No unproven paid plan can trigger automatic vendor activation.
- Entitlement definition (`vendor_plans`) is decoupled from multi-currency billing prices (`vendor_plan_prices`).
