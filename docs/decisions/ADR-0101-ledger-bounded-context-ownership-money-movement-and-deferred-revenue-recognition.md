# ADR-0101: Ledger Bounded Context Ownership, Money-Movement Boundary & Deferred Revenue Recognition

## Status
Accepted

## Context
In an enterprise multi-tenant commerce engine, financial accounting requires strict immutability, mathematical double-entry balance, and absolute domain separation from commercial pricing, promotion evaluation, and payment gateway interactions. Blurring these responsibilities leads to severe failure modes:
1. Recalculating taxes, item discounts, or commercial totals inside the accounting ledger.
2. Prematurely hardcoding revenue recognition policies (e.g. cash-basis vs accrual/fulfillment-basis) without authoritative commercial allocation data.
3. Forcing payment transactions (which only know execution amounts and currencies) to guess line-item merchandise, shipping, or tax allocations.
4. Conflating the commercial merchant-of-record role (platform vs seller) with underlying ledger primitives.

## Decision
1. **Dedicated Ledger Bounded Context**: `modules/Ledger` is established as an autonomous modular bounded context. Ledger owns the double-entry general ledger, tenant Chart of Accounts, journal entries, journal lines, and query-time balance derivations.
2. **Unidirectional Dependency Direction**:
   $$\text{modules/Ledger} \longrightarrow \text{modules/Payment} \quad \text{and} \quad \text{modules/Ledger} \longrightarrow \text{modules/Order}$$
   Neither `Payment` nor `Order` ever imports or depends upon `Ledger`.
3. **Movement-Only Scope for Phase-10**:
   Phase-10 Payment integration records *only* authoritative customer-funds movement:
   - Successful purchase/capture: Debit `payment_clearing`, Credit `customer_funds_liability` for the exact `PaymentTransaction.amount_minor`.
   - Successful refund: Debit `customer_funds_liability`, Credit `payment_clearing` for the exact `PaymentTransaction.amount_minor`.
4. **Deferred Commercial Revenue & Tax Recognition**:
   Commercial revenue recognition (crediting sales revenue, shipping revenue, tax liabilities) and promotional discount presentation are explicitly deferred until an authoritative commercial accounting allocation contract is introduced. Ledger does not invent or calculate proportional allocations.
5. **Merchant-of-Record Neutrality**:
   The Ledger primitives remain neutral to commercial operating models (platform-as-MoR, seller-as-MoR, or marketplace agent), avoiding speculative cross-domain columns.

## Consequences
- Clean, mathematically sound money-movement accounting is achieved immediately.
- Payment integration never fails due to missing line-item tax or promotion metadata.
- Upstream commercial recognition can be added additively without altering Phase-10 ledger primitives.
