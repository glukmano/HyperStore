# ADR-0094: Provider-Neutral Gateway & Transaction Attempt Architecture

## Status
Accepted

## Context
A platform supporting multiple payment gateways (e.g. Stripe, Adyen, PayPal, Mollie, internal gateways) must decouple its domain models from provider-specific data structures, SDKs, and error codes. Furthermore, a single order payment obligation may experience multiple gateway attempts (e.g. an initial card decline followed by a successful retry using another card).

Modeling payment solely as a single gateway attempt causes obligation termination on temporary card declines, forcing premature order cancellation.

## Decision
1. **Separation of Obligation and Attempt**:
   - `Payment` represents the commercial order payment obligation with cardinality `UNIQUE(tenant_id, order_id)`.
   - `PaymentTransaction` represents an individual provider attempt or internal settlement operation.
2. **Provider-Neutral Interface**:
   All gateway integrations implement `PaymentGatewayInterface`:
   - `purchase(GatewayPaymentRequest): GatewayPaymentResult`
   - `authorize(GatewayPaymentRequest): GatewayPaymentResult`
   - `capture(GatewayCaptureRequest): GatewayPaymentResult`
   - `refund(GatewayRefundRequest): GatewayPaymentResult`
   - `void(GatewayVoidRequest): GatewayPaymentResult`
3. **Driver Isolation**:
   Core domain code contains zero third-party gateway SDK imports. Drivers are registered via `PaymentGatewayRegistryInterface`.
4. **Retry Independence**:
   A failed `PaymentTransaction` leaves the parent `Payment` obligation in `pending`, enabling customer retry without creating duplicate commercial obligations.

## Consequences
- The system supports multiple payment attempts per order obligation without data duplication.
- Adding new payment providers requires only implementing an adapter, with zero core schema changes.
