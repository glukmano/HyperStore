# ADR-0093: Payment Bounded Context Ownership, Unidirectional Order Decoupling & Event Synchronization

## Status
Accepted

## Context
In enterprise commerce architectures, payment execution and commercial orders have fundamentally different lifecycles and operational concerns. Orders represent the immutable commercial contract (lines, quantities, taxes, customer details, and total obligation), whereas payments represent the execution, authorization, settlement, and provider-specific interactions required to satisfy that obligation.

Tight coupling between orders and payment gateways introduces significant failure modes:
1. Re-running commercial checkout/pricing logic during payment retries.
2. Gateway SDK and network dependencies polluting core order models.
3. Order state machines becoming cluttered with transient provider states (e.g. 3DS redirects, card declines).
4. Circular dependencies between Order and Payment modules.

## Decision
1. **Bounded Context Separation**: `modules/Payment` is established as an independent bounded context. Payment owns payment attempts, transactions, gateway provider interactions, and authorization/settlement balances.
2. **Unidirectional Dependency Direction**:
   $$\text{modules/Payment} \longrightarrow \text{modules/Order}$$
   `modules/Order` has zero awareness, zero class imports, and zero dependencies on `modules/Payment`.
3. **Dedicated Order-Owned Synchronization Contract**:
   `modules/Order` defines and implements `OrderPaymentSynchronizationServiceInterface`. Payment application services invoke this interface to project payment state milestones into `Order.payment_status`.
4. **Order-Owned Event Consumption**:
   Payment listens to Order-owned domain events (such as `OrderCancelled`). Payment imports the Order event; Order never imports Payment.
5. **No Auto-Confirmation of Orders**:
   Successful payment capture transitions `Order.payment_status` to `paid`. It does not automatically confirm `Order.order_status` (which remains `placed`). Order lifecycle confirmation remains an independent business operation.

## Consequences
- Clean, unidirectional module graph avoiding circular dependencies.
- Order domain remains completely agnostic of payment providers, gateway SDKs, and network protocols.
- Payment failures and retries do not alter or mutate the commercial order contract.
