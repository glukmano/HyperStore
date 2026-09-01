# ADR-0072: Coupon Lifecycle, Scope Validation, and Temporary Hold Strategy

## Status
Accepted

## Context
Promotional coupons must be validated against customer eligibility, tenant/store/channel scope, usage limits, and minimum cart subtotals without prematurely consuming single-use coupons during casual browsing.

## Decision
1. **Validation Pipeline**:
   - Coupons are evaluated through `modules/Promotions/` contracts.
   - Validates code normalization (uppercase/trimmed), date validity, store/channel scope, and minimum subtotal.
2. **Non-Destructive Application**:
   - Applying a coupon in Cart/Checkout checks eligibility without incrementing persistent usage counts.
   - Actual usage consumption is deferred to terminal Order creation in future phases.
   - Checkout tracks applied coupon codes and discount amounts in the pricing snapshot.

## Consequences
- No burned coupons on abandoned carts.
- Strict promotional scope validation.
