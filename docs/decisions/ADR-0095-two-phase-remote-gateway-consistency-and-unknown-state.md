# ADR-0095: Two-Phase Remote Gateway Consistency, UNKNOWN State & Out-of-Band Reconciliation

## Status
Accepted

## Context
Remote payment gateway calls across HTTP cannot participate in local relational database ACID transactions. Wrapping remote API calls in database transactions leads to connection pool exhaustion, long locks, and distributed inconsistency if the database commit fails after remote funds have already been charged.

Furthermore, network timeouts, connection drops, and HTTP 5xx responses create an indeterminate state: the local caller does not know whether the gateway processed the charge. Treating a timeout as a failure risks double-charging the customer upon retry.

## Decision
1. **Three-Step Consistency Protocol**:
   - **Step 1 (Pre-Call DB Commit)**: In a local transaction, acquire an aggregate idempotency lease, insert `Payment` (`pending`) and `PaymentTransaction` (`pending`), and commit immediately.
   - **Step 2 (Remote Gateway Call)**: Invoke the gateway outside any database transaction using a deterministic `provider_idempotency_key`.
   - **Step 3 (Post-Call Reconciliation DB Commit)**: In a new local transaction, lock the records (`SELECT ... FOR UPDATE`), update transaction status, update payment totals, and sync Order status.
2. **UNKNOWN State Semantics**:
   - If a network timeout or connection error occurs, `PaymentTransaction.status` is set to `unknown` (NOT `failure`).
   - The operation is preserved, preventing blind monetary retries.
3. **Out-of-Band Reconciliation Contract**:
   Gateways implement `PaymentGatewayReconciliationInterface`:
   ```php
   public function supportsReconciliation(): bool;
   public function reconcileOperation(GatewayReconciliationRequest $request): GatewayReconciliationResult;
   ```
   When a retry occurs on an `unknown` transaction, the system calls `reconcileOperation()` using the existing `provider_idempotency_key` rather than executing `purchase()` again.

## Consequences
- Guaranteed zero double-charges on network timeouts.
- Database locks are kept minimal (sub-millisecond), eliminating lock contention during external network latencies.
