---
name: testing-quality
description: Enforces Pest test suites, static analysis (Larastan/PHPStan level 8+), code style (Pint), tenant isolation tests, and financial invariant assertions. Use for test writing, test suite execution, and CI validation.
---

# Testing Standards & Quality Assurance

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 27, 35)

## Core Rules & Mandates

1. **No Untested Features**:
   - Every feature, endpoint, domain action, and bugfix must be accompanied by comprehensive automated tests using **Pest**.
   - No phase can be accepted without green tests.
2. **Mandatory High-Risk Coverage**:
   - Automated tests are mandatory for:
     - Tenant and Store data isolation.
     - Multi-vendor checkout and order splitting.
     - Arbitrary-precision money arithmetic and currency conversions.
     - Ledger balance integrity and zero-sum verification.
     - Inventory reservations and race-condition oversell prevention.
     - Vendor commission calculations, refunds, and payouts.
     - Affiliate referral attribution windows.
     - AI autonomy permissions and emergency kill switch.
3. **Static Analysis & Formatting**:
   - **Larastan / PHPStan Level 8+** with zero baseline errors.
   - **Laravel Pint** formatting enforced across all PHP files.
4. **Test Immutability**:
   - **NEVER weaken, comment out, or delete existing assertions** merely to make a failing implementation pass. Fix the underlying implementation.

## Pre-Execution Checklist
- [ ] Are Pest tests written covering happy paths, edge cases, and failure modes?
- [ ] Are high-risk financial and concurrency invariants tested?
- [ ] Does Larastan / PHPStan pass cleanly at Level 8+?

## Forbidden Shortcuts
- ❌ Deleting or weakening test assertions to achieve a passing build.
- ❌ Skipping concurrency or race-condition testing on inventory/checkout.
- ❌ Ignoring PHPStan level 8 type warnings.

## Validation Steps
1. Run `vendor/bin/pest --parallel` (all tests passing).
2. Run `vendor/bin/phpstan analyse --level=8`.
3. Run `vendor/bin/pint --test`.
