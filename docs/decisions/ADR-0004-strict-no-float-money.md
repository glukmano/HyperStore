# ADR-0004: Strict No-Float Money — Integer Minor Units Only

| Field        | Value                                |
|-------------|--------------------------------------|
| ID          | ADR-0004                             |
| Status      | Accepted                             |
| Date        | 2026-08-31                           |
| Deciders    | Project Lead, Platform Architect     |
| Phase       | PHASE-01                             |

## Context

Monetary values in a commerce platform are safety-critical. Floating-point arithmetic is
fundamentally unsuitable for money due to IEEE 754 representation errors.

Example: `0.1 + 0.2 === 0.3` evaluates to `false` in PHP (and most languages).
In a financial ledger, even a single cent of rounding error per transaction compounds
across thousands of transactions into significant financial discrepancies.

## Decision

**Money MUST NEVER be represented as a float anywhere in the platform.**

### Rules (enforced by architecture tests):

1. **Storage**: Money is stored as `bigint` in PostgreSQL, representing the smallest currency unit (minor units). Example: `$19.99 USD` → `1999`.
2. **PHP types**: Money values in PHP code must use `int` (minor units), never `float` or `double`.
3. **Money library**: `brick/money` will be installed in the Pricing/Ledger/Payments phase (Phase 05+). Its `Money` value object will wrap all monetary arithmetic.
4. **Serialization**: Money is transmitted as an integer in JSON APIs (minor units) or as a formatted string with currency code. Never as a decimal float.
5. **Display**: Formatting for display is the UI layer's responsibility. Use `NumberFormatter` or the `brick/money` formatter, never manual string interpolation.
6. **Database CHECK constraints**: All `price`, `amount`, `total` columns must have `CHECK (column >= 0)` unless the column explicitly stores negative values (refunds, credits).

### Violation examples (FORBIDDEN):

```php
// ❌ FORBIDDEN
$price = 19.99;
$price = (float) $request->input('price');
$total = $subtotal * 1.2; // float multiplication

// ✅ CORRECT
$priceMinorUnits = 1999; // int: $19.99 in USD minor units
```

## Consequences

- Phase 01 establishes this as an architectural invariant only — no monetary code is written.
- `brick/money` will be installed when the first monetary domain code is implemented (Pricing/Ledger Phase).
- Architecture tests in `tests/Unit/ArchitectureTest.php` will assert no `float` appears in money-related code.
- All code reviewers must reject PRs that introduce `float` for monetary values.

## References

- PROJECT_MASTER_PLAN.md §Financial Integrity
- [brick/money](https://github.com/brick/money)
- [IEEE 754 Floating Point](https://floating-point-gui.de/)
- ADR-0002 (PostgreSQL — bigint money columns)
