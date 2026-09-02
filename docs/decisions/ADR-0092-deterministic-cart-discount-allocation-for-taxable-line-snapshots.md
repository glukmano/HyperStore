# ADR-0092: Deterministic Cart Discount Allocation for Taxable Line Snapshots

## Status
Accepted

## Context
Global taxation principles (e.g. EU VAT, UK VAT, state sales taxes) require taxes to be evaluated on the net taxable amount after applicable commercial discounts have been subtracted (\text{Merchandise Subtotal} - \text{Discounts} = \text{Taxable Base}).

In the Hyper Commerce modular architecture:
- `modules/Promotions/` evaluates promotion rules and coupons, emitting aggregate cart-level and line-level discounts.
- `modules/Checkout/` orchestrates pricing, promotions, taxes, shipping, and inventory reservations, producing an immutable `CheckoutReadyResult` and `ready_snapshot`.
- `modules/Order/` consumes the immutable terminal snapshot from Checkout without recalculating prices, re-evaluating promotions, or re-allocating taxes.

When cart-level monetary discounts (e.g. 20% off cart total, $10 off coupon) are applied, each line item requires an authoritative, immutable record of its exact allocated discount and resulting taxable base for order history, item invoicing, partial cancellations, and financial ledger accounting. Concurrently, the sum of all allocated line discounts must reconcile exactly to the total cart discount, and the sum of all line taxes must reconcile exactly to the total checkout tax without any fractional penny drift or floating-point imprecision.

## Decision

### 1. Proportional Allocation by Merchandise Subtotal
Cart-level monetary discounts are allocated proportionally across eligible cart lines based on each line's merchandise subtotal weight relative to the total eligible merchandise subtotal:
$$\text{Proportional Weight}_i = \frac{\text{merchandise\_line\_subtotal\_minor}_i}{\sum_{j \in \text{Eligible}} \text{merchandise\_line\_subtotal\_minor}_j}$$

### 2. Float-Free Integer Minor Units Only
All calculations are performed strictly using integer minor units and integer arithmetic (`bcmul`, `bcdiv`, `bcmod`, or integer quotient/modulo). No floating-point operations (`float`, `double`) are permitted anywhere in the allocation or tax pipeline.

### 3. Deterministic Largest Remainder Rounding (Hamilton-Hare Method)
To distribute undivided minor units (pennies) without loss or arbitrary drift:
1. Compute the floor share for each eligible line:
   $$\text{floor\_share}_i = \left\lfloor \frac{\text{discount\_minor} \times \text{weight}_i}{\text{total\_weight}} \right\rfloor$$
2. Compute the exact integer remainder for each line:
   $$\text{remainder}_i = (\text{discount\_minor} \times \text{weight}_i) \pmod{\text{total\_weight}}$$
3. Determine the undistributed minor units:
   $$\text{undistributed} = \text{discount\_minor} - \sum_{i} \text{floor\_share}_i$$
4. Sort eligible lines by:
   - `remainder` in descending order.
   - Tie-breaker: `cart_line_id` in ascending order (guaranteeing deterministic tie resolution).
5. Distribute exactly 1 minor unit to each of the first `undistributed` lines in the sorted sequence.

### 4. Promotion Eligibility Scope & Architectural Separation of Concerns
- **Promotions Domain Owns Eligibility Semantics**: `PromotionRuleEngine` is the single authoritative evaluator for promotion conditions and actions. It evaluates conditions and action targets using registered handlers (via `PromotionItemFilterConditionInterface`, `PromotionItemFilterActionInterface`, and `PromotionLineEligibilityResolverInterface`) and attaches the authoritative `eligibleCartLineIds: list<int>` to every monetary `DiscountLine`.
- **Checkout Domain Owns Monetary Allocation Mathematics**: `CheckoutPricingOrchestrator` consumes the immutable `DiscountLine` collection and executes proportional allocation mathematics. Checkout contains ZERO logic inspecting `Promotion` models, reading `PromotionCondition` records, switching on `condition_type`, interpreting category/product IDs, or deciding action targeting.
- **Fail-Closed Validation**: If any monetary `DiscountLine` carries empty, duplicate, or unknown `cart_line_id`s not present in the current cart, Checkout fails closed immediately. No fallback-to-all-lines is permitted under any circumstances.
- **Sequential Multi-Discount Allocation**: Multiple promotions are evaluated deterministically in `PromotionRuleEngine` priority order. Each subsequent discount allocates proportionally across its specific `eligibleCartLineIds` against remaining subtotal capacity without double-allocation or negative lines.

### 5. Non-Negative Taxable Amount Safety Invariant
For every line:
$$\text{allocated\_cart\_discount\_minor} \ge 0$$
$$\text{line\_discount\_minor} + \text{allocated\_cart\_discount\_minor} \le \text{merchandise\_line\_subtotal\_minor}$$
$$\text{taxable\_amount\_minor} = \text{merchandise\_line\_subtotal\_minor} - \text{line\_discount\_minor} - \text{allocated\_cart\_discount\_minor} \ge 0$$
If any allocation causes a line's taxable amount to become negative or fails exact mathematical reconciliation, the checkout pipeline fails closed immediately.

### 6. Line Tax and Total Semantics
1. Tax is calculated per line using `TaxCalculator` directly on `taxable_amount_minor`:
   $$\text{tax\_minor} = \text{TaxCalculator}(\text{taxable\_amount\_minor}, \text{tax\_class\_id}, \text{TaxContext})$$
2. Line total is defined authoritatively as:
   $$\text{line\_total\_minor} = \text{merchandise\_line\_subtotal\_minor} - \text{line\_discount\_minor} - \text{allocated\_cart\_discount\_minor} + \text{tax\_minor}$$
3. Every pricing line persists:
   - `cart_line_id`, `product_id`, `variant_id`, `quantity`
   - `unit_price_minor`, `merchandise_line_subtotal_minor`
   - `line_discount_minor`, `allocated_cart_discount_minor`
   - `taxable_amount_minor`, `tax_minor`, `line_total_minor`
   - `tax_class_id`, `tax_rate_percent`, `currency`

### 7. Strict Reconciliation Invariants
- $\sum \text{line.merchandise\_line\_subtotal\_minor} === \text{totals.merchandise\_subtotal}$
- $\sum \text{line.line\_discount\_minor} === \text{totals.line\_discounts}$
- $\sum \text{line.allocated\_cart\_discount\_minor} === \text{totals.cart\_discounts}$
- $\sum \text{line.tax\_minor} === \text{totals.tax\_total}$
- $\text{grand\_total} === \text{merchandise\_subtotal} - \text{line\_discounts} - \text{cart\_discounts} + \text{shipping\_final} + \text{tax\_total}$

### 8. Order Module Boundary
`modules/Order/` consumes the immutable `pricing_snapshot.lines` directly. It does not reallocate discounts, does not recalculate taxes, and does not read mutable Catalog/Pricing/Promotion models.

## Consequences
- 100% compliant net-taxable base calculation across all tax jurisdictions.
- Deterministic penny allocation with zero rounding drift.
- Transparent line-item financial history enabling unambiguous partial returns, line refunds, and tax remittance ledger postings.
