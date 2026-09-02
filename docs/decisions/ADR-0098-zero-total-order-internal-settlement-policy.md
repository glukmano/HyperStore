# ADR-0098: Zero-Total Order Internal Settlement Policy

## Status
Accepted

## Context
Promotional discounts, 100% off coupons, loyalty rewards, or free sample items may result in valid orders where `order.grand_total_minor === 0`.

Treating zero-total orders as external payment gateway operations introduces unnecessary network overhead and fails when gateways reject zero-amount transactions. Conversely, bypassing payment entirely breaks reporting and leaves the order in an unpaid state.

## Decision
1. **Internal Application Settlement**:
   - Zero-total orders are settled via an internal application path within `PaymentInitiationService`.
   - `PaymentGatewayInterface` is never invoked.
2. **Authoritative Record**:
   - Creates a `Payment` with `amount_minor = 0`, `captured_amount_minor = 0`, `status = 'captured'`.
   - Creates a `PaymentTransaction` with `operation_type = 'zero_total_settlement'`, `status = 'success'`, `amount_minor = 0`, `provider_code = NULL`, `provider_reference = NULL`.
3. **Order Projection**:
   - Calls `OrderPaymentSynchronizationServiceInterface->syncPaymentStatus(..., PaymentStatus::PAID)`.
4. **Idempotency**:
   - Uses the standard aggregate-scoped idempotency mechanism to ensure concurrent retries yield identical results with zero duplicate entries.

## Consequences
- Clean, auditable internal settlement for free orders without calling external payment networks.
- Exact compliance with the principle that zero is a valid monetary value in integer minor units.
