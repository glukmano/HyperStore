# ADR-0110: Commission Engine & Checkout READY Snapshot Freezing

## Status
ACCEPTED

## Date
2026-09-03

## Context
Per Phase-08 architecture, Order creation consumes the immutable Checkout `ready_snapshot` verbatim. Historical orders must not query live marketplace rules or recompute commissions. Furthermore, commission must have an unambiguous monetary basis, integer arithmetic, and deterministic rule precedence.

## Decision
1. **Net Merchandise Basis**: Commission applies strictly to the net merchandise line total:
   $$\text{Commission Basis} = \text{merchandise\_line\_subtotal\_minor} - \text{line\_discount\_minor} - \text{allocated\_cart\_discount\_minor}$$
   Taxes and shipping are strictly excluded.
2. **Deterministic Integer Math**: Half-up rounding in basis points ($100\text{ bps} = 1.00\%$):
   $$\text{Variable Commission} = \left\lfloor \frac{(\text{Basis Minor} \times \text{Rate Bps}) + 5000}{10000} \right\rfloor$$
   $$\text{Total Commission} = \text{Variable Commission} + \text{Fixed Fee Minor}$$
   Guard: $0 \le \text{Total Commission} \le \text{Basis Minor}$. Currency mismatch on fixed fees fails closed.
3. **Precedence & Partial Uniqueness**: Precedence is: Vendor+Category $\to$ Vendor Global $\to$ Plan Base $\to$ Tenant Default. Database partial unique indexes prevent duplicate active rules within the same scope.
4. **Checkout Snapshot Freezing**: Checkout resolves vendor listings and quotes commissions during ready-snapshot preparation. Order creation persists snapshot fields onto `order_items` without calling live Marketplace services.

## Consequences
- Historical orders remain 100% immutable and independent of future vendor renames, plan changes, or commission edits.
- Integer arithmetic eliminates floating-point rounding errors.
