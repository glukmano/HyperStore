---
name: money-ledger
description: Enforces immutable double-entry style financial ledger, minor units / brick/money, commission calculation, refund/payout workflows, and idempotency. Use for all code touching pricing, payments, commissions, wallets, payouts, or ledger entries.
---

# Money, Currency & Financial Ledger

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 15, 26, 27)

## Core Rules & Mandates

1. **Strict No-Float Rule**:
   - **NEVER use binary floating-point types (`float`, `double`) for money.**
   - Store monetary values in minor units (e.g. integer cents/fils) and/or use `brick/money` for arbitrary-precision arithmetic.
   - Every monetary figure must carry an explicit Currency Context.
2. **Immutable Double-Entry Ledger**:
   - Maintain an auditable internal financial ledger.
   - Account balances (Customer Wallet, Vendor Payables, Platform Revenue, Affiliate Commissions, Taxes) are derived from ledger movements, NOT arbitrary editable balance fields.
   - Financial records are append-only and strictly immutable.
3. **Commission & Split Payments**:
   - Commissions are calculated using transparent rules (platform default, vendor plan, category, product type, market, payment method).
   - Support both platform collection + Vendor payout and gateway-native split payments.
4. **Idempotency Mandatory**:
   - All state-mutating financial operations (payments, refunds, payouts, transfers) require idempotency keys to prevent duplicate charges or double payouts.

## Pre-Execution Checklist
- [ ] Are prices and totals calculated with `brick/money` or minor integer arithmetic?
- [ ] Is an idempotency key required on payment/payout endpoints?
- [ ] Are all financial ledger movements strictly balanced?
- [ ] Is currency conversion handled explicitly with recorded exchange rates?

## Forbidden Shortcuts
- ❌ Using PHP `float` or SQL `FLOAT`/`DOUBLE` for prices, taxes, or balances.
- ❌ Directly incrementing/decrementing a balance column without creating an immutable ledger transaction.
- ❌ Non-idempotent payment charge or payout methods.

## Validation Steps
1. Execute unit tests verifying zero rounding errors in multi-currency commission splits.
2. Verify duplicate submission rejection via idempotency keys.
3. Assert that ledger entry sums always match calculated account balances.
