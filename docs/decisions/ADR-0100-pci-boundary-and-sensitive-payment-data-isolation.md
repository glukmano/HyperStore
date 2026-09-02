# ADR-0100: PCI Boundary & Sensitive Payment Data Isolation

## Status
Accepted

## Context
Payment Card Industry Data Security Standards (PCI-DSS) impose severe compliance, security, and auditing obligations on systems that capture, store, or process raw Primary Account Numbers (PAN), Card Verification Values (CVV), or magnetic stripe data.

A modern e-commerce modular monolith must minimize its PCI compliance scope (e.g. SAQ-A or SAQ-A-EP) by never permitting raw cardholder data to touch its servers or databases.

## Decision
1. **Zero Cardholder Data Persistence**:
   - No table, column, cache, or log file may accept, transmit, or persist raw PAN, CVV, or card PINs.
   - Provider integrations rely strictly on hosted checkout fields, client-side SDK tokens, or redirect workflows.
2. **Ephemeral Action Payloads**:
   - `PaymentTransaction.action_payload` may contain only safe client-facing values (e.g. redirect URLs, client secrets, ephemeral public tokens).
   - Server-side secret keys and webhook signing secrets are strictly excluded from client-facing payloads.
3. **Diagnostic Sanitization**:
   - API resources and logging drivers sanitize and mask all diagnostic outputs.
   - Guest order tokens are never stored in payment records or logged.

## Consequences
- The platform operates strictly outside the scope of raw card data processing.
- Massive reduction in security vulnerability surface and regulatory compliance overhead.
