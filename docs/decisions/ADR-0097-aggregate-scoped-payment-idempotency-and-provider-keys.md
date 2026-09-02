# ADR-0097: Aggregate-Scoped Payment Idempotency & Provider Idempotency Derivation

## Status
Accepted

## Context
Payment processing requires durable idempotency to prevent duplicate charges caused by client retries, double-clicks, or automated polling. 

However, duplicating idempotency keys across both an operation tracking table and a transaction log creates ambiguous ownership. Furthermore, a distinction must exist between client-supplied idempotency tokens and provider-facing idempotency keys.

## Decision
1. **Single Internal Idempotency Authority**:
   - `payment_operation_keys` is the single authority for durable operation leasing, request hashing, and response replay.
   - `payment_transactions` does not store an independent client idempotency key; it references `payment_operation_key_id` as a foreign key.
2. **Four Distinct Key Concepts**:
   - **Client Idempotency Key**: Caller-supplied header string (stored in `payment_operation_keys.idempotency_key`).
   - **Internal Operation Identity**: Primary key `payment_operation_keys.id`.
   - **Provider Idempotency Key**: Deterministic internal string sent to the remote payment gateway (stored in `payment_transactions.provider_idempotency_key`), derived from `payment_transactions.id` and request hash.
   - **Provider Transaction Reference**: External identifier returned by the gateway (stored in `payment_transactions.provider_reference`).
3. **Aggregate-Scoped Uniqueness**:
   - Creation: `UNIQUE (tenant_id, order_id, operation_type, idempotency_key)` where `operation_type = 'initiate_payment'`.
   - Mutation: `UNIQUE (tenant_id, payment_id, operation_type, idempotency_key)` where `payment_id IS NOT NULL`.
4. **Provider Idempotency Uniqueness**:
   - `UNIQUE (tenant_id, provider_code, provider_idempotency_key)` where `provider_idempotency_key IS NOT NULL`.
   - Prevents two transaction records from representing identical remote execution identities.

## Consequences
- Guaranteed zero duplicate charges across network retries.
- Client keys are safely scoped per aggregate, allowing the same key to be used across different orders.
